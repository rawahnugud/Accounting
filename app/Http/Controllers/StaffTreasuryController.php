<?php

namespace App\Http\Controllers;

use App\Accounting\BranchAccessDenied;
use App\Accounting\Treasury;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StaffTreasuryController
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'Unable to sign in with those details.']);
        }
        $request->session()->regenerate();
        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    private function assignments(Request $request)
    {
        return DB::table('branch_staff as staff')
            ->join('branches as b', 'b.id', '=', 'staff.branch_id')
            ->join('companies as c', 'c.id', '=', 'staff.company_id')
            ->where('staff.user_id', $request->user()->getAuthIdentifier())
            ->select('b.id', 'b.name', 'c.id as company_id', 'c.name as company', 'c.reporting_currency', 'staff.role');
    }

    public function home(Request $request)
    {
        $branches = $this->assignments($request)->orderBy('company')->orderBy('b.name')->get();
        // Single-branch deployment: skip the picker when there is only one branch to open.
        if ($branches->count() === 1) {
            return redirect('/branches/'.$branches->first()->id);
        }
        return view('accounting.branches', compact('branches'));
    }

    private static function money(int $minor, int $decimals): string
    {
        if ($decimals === 0) {
            return number_format($minor);
        }
        $digits = str_pad((string)$minor, $decimals + 1, '0', STR_PAD_LEFT);
        return number_format((int)substr($digits, 0, -$decimals)).'.'.substr($digits, -$decimals);
    }

    private static function decimals(string $currency): int
    {
        return (int)DB::table('currencies')->where('code', $currency)->value('decimals');
    }

    public function branch(Request $request, int $branch)
    {
        $assignment = $this->assignments($request)->where('b.id', $branch)->firstOrFail();
        $accounts = DB::table('cash_accounts')->where('branch_id', $branch)
            ->where('currency', $assignment->reporting_currency)->orderBy('name')->get();
        $decimals = self::decimals($assignment->reporting_currency);
        $transfers = DB::table('treasury_transfers as t')
            ->join('cash_accounts as src', 'src.id', '=', 't.source_id')
            ->join('cash_accounts as dst', 'dst.id', '=', 't.destination_id')
            ->leftJoin('users as u', DB::raw("'staff:' || u.id"), '=', 't.actor')
            ->where('t.branch_id', $branch)->orderByDesc('t.id')->limit(20)
            ->get(['t.id', 't.posted_at', 't.currency', 't.amount_minor', 'src.name as source',
                'dst.name as destination', DB::raw('coalesce(u.name, t.actor) as actor')])
            ->each(fn($t) => $t->amount = self::money((int)$t->amount_minor, $decimals));
        return view('accounting.branch', compact('assignment', 'accounts', 'transfers'));
    }

    public function transfer(Request $request, int $branch)
    {
        $assignment = $this->assignments($request)->where('b.id', $branch)->firstOrFail();
        abort_unless($assignment->role === 'cashier', 403);
        $data = $request->validate([
            'source' => 'required|integer', 'destination' => 'required|integer|different:source',
            'amount' => 'required|string|max:40', 'request_key' => 'required|uuid',
        ], ['destination.different' => 'Choose a To account different from the From account.'],
            ['source' => 'From account', 'destination' => 'To account']);
        // Accept typed thousands separators such as "1,000.50" or "1 000".
        $amount = str_replace([',', ' '], '', $data['amount']);
        $accounts = DB::table('cash_accounts')->where('branch_id', $branch)
            ->where('currency', $assignment->reporting_currency)
            ->whereIn('id', [$data['source'], $data['destination']])->pluck('name', 'id');
        abort_unless($accounts->count() === 2, 422);
        try {
            $id = (new Treasury(DB::connection()->getPdo()))->transferForStaff(
                (int)$request->user()->getAuthIdentifier(), $assignment->company_id, $branch,
                $data['source'], $data['destination'], $assignment->reporting_currency,
                $amount, $data['request_key']
            );
        } catch (BranchAccessDenied $e) {
            abort(403);
        } catch (\InvalidArgumentException|\DomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        $minor = (int)DB::table('treasury_transfers')->where('id', $id)->value('amount_minor');
        return redirect('/branches/'.$branch)->with('message', sprintf('Transfer #%d recorded: %s %s from %s to %s.',
            $id, self::money($minor, self::decimals($assignment->reporting_currency)), $assignment->reporting_currency,
            $accounts[$data['source']], $accounts[$data['destination']]));
    }
}
