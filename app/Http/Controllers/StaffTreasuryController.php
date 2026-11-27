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
        return view('accounting.branches', ['branches' => $this->assignments($request)->orderBy('company')->orderBy('b.name')->get()]);
    }

    public function branch(Request $request, int $branch)
    {
        $assignment = $this->assignments($request)->where('b.id', $branch)->firstOrFail();
        $accounts = DB::table('cash_accounts')->where('branch_id', $branch)
            ->where('currency', $assignment->reporting_currency)->orderBy('name')->get();
        return view('accounting.branch', compact('assignment', 'accounts'));
    }

    public function transfer(Request $request, int $branch)
    {
        $assignment = $this->assignments($request)->where('b.id', $branch)->firstOrFail();
        abort_unless($assignment->role === 'cashier', 403);
        $data = $request->validate([
            'source' => 'required|integer', 'destination' => 'required|integer|different:source',
            'amount' => 'required|string|max:40', 'request_key' => 'required|uuid',
        ]);
        $accounts = DB::table('cash_accounts')->where('branch_id', $branch)
            ->where('currency', $assignment->reporting_currency)
            ->whereIn('id', [$data['source'], $data['destination']])->count();
        abort_unless($accounts === 2, 422);
        try {
            $id = (new Treasury(DB::connection()->getPdo()))->transferForStaff(
                (int)$request->user()->getAuthIdentifier(), $assignment->company_id, $branch,
                $data['source'], $data['destination'], $assignment->reporting_currency,
                $data['amount'], $data['request_key']
            );
        } catch (BranchAccessDenied $e) {
            abort(403);
        } catch (\InvalidArgumentException|\DomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        return redirect('/branches/'.$branch)->with('message', 'Transfer #'.$id.' recorded.');
    }
}
