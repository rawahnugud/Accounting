<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\Amounts;
use App\Accounting\BranchAccessDenied;
use App\Accounting\Treasury;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class StaffTreasuryController extends AbstractController
{
    public function __construct(private PDO $db) {}

    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }
        $error = $auth->getLastAuthenticationError();
        return $this->render('login.html.twig', ['failed' => $error !== null,
            'throttled' => $error instanceof TooManyLoginAttemptsAuthenticationException, 'email' => $auth->getLastUsername()]);
    }

    /** Emails a one-hour reset link through the host's PHP mail(); the reply never reveals whether the email exists. */
    public function forgotPassword(Request $request): Response
    {
        $sent = false;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('staff', $request->request->get('_token'))) {
                $this->addFlash('error', 'This form expired. Check the details and submit again.');
                return $this->redirectToRoute('forgot_password');
            }
            $email = trim((string)$request->request->get('email'));
            $user = $this->db->prepare('SELECT email FROM users WHERE email = ?');
            $user->execute([$email]);
            $recent = $this->db->prepare("SELECT 1 FROM password_reset_tokens WHERE email = ? AND created_at > datetime('now','-2 minutes')");
            $recent->execute([$email]);
            if ($user->fetchColumn() && !$recent->fetchColumn()) {
                $token = bin2hex(random_bytes(32));
                $this->db->prepare("INSERT INTO password_reset_tokens(email, token, created_at) VALUES (?, ?, datetime('now'))
                    ON CONFLICT(email) DO UPDATE SET token = excluded.token, created_at = excluded.created_at")->execute([$email, hash('sha256', $token)]);
                $link = $this->generateUrl('reset_password', ['token' => $token], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
                @mail($email, 'Reset your Midnight Travel accounting password',
                    "Open this link within one hour to choose a new password:\n\n$link\n\nIf you did not ask for this, ignore this email.",
                    'From: Midnight Travel Accounting <no-reply@'.$request->getHost().'>');
            }
            $sent = true;
        }
        return $this->render('forgot_password.html.twig', ['sent' => $sent]);
    }

    public function resetPassword(string $token, Request $request): Response
    {
        $find = $this->db->prepare("SELECT email FROM password_reset_tokens WHERE token = ? AND created_at > datetime('now','-60 minutes')");
        $find->execute([hash('sha256', $token)]);
        $email = $find->fetchColumn();
        $error = $email ? null : 'This reset link is invalid or has expired. Ask for a new one.';
        if ($email && $request->isMethod('POST')) {
            $password = (string)$request->request->get('password');
            if (!$this->isCsrfTokenValid('staff', $request->request->get('_token'))) {
                $error = 'This form expired. Check the details and submit again.';
            } elseif (strlen($password) < 12 || strlen($password) > 72) {
                $error = 'Enter a 12 to 72 byte password.';
            } elseif ($password !== (string)$request->request->get('confirm')) {
                $error = 'The two passwords do not match.';
            } else {
                $this->db->prepare('UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE email = ?')->execute([password_hash($password, PASSWORD_BCRYPT), $email]);
                $this->db->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$email]);
                $this->addFlash('message', 'Password changed. Sign in with the new password.');
                return $this->redirectToRoute('login');
            }
        }
        return $this->render('reset_password.html.twig', ['error' => $error, 'valid' => (bool)$email]);
    }

    public function logout(): never
    {
        throw new \LogicException('The firewall handles sign-out.');
    }

    public function health(): Response
    {
        return new Response('OK');
    }

    /** Touched by open pages while staff are typing, so a long form does not outlive the session. */
    public function ping(): Response
    {
        return new Response('', 204);
    }

    private function assignments(?int $branch = null): array
    {
        $sql = 'SELECT s.user_id, s.company_id, s.role, b.id, b.name, c.name AS company, c.reporting_currency
            FROM branch_staff s JOIN users u ON u.id = s.user_id
            JOIN branches b ON b.id = s.branch_id AND b.company_id = s.company_id
            JOIN companies c ON c.id = s.company_id WHERE u.email = ?';
        if ($this->isGranted('ROLE_ADMIN')) {
            $sql = "SELECT u.id AS user_id,b.company_id,'admin' AS role,b.id,b.name,c.name AS company,c.reporting_currency
                FROM branches b JOIN companies c ON c.id=b.company_id JOIN users u ON u.email=? AND u.is_admin=1 WHERE 1=1";
        }
        $params = [$this->getUser()->getUserIdentifier()];
        if ($branch !== null) {
            $sql .= ' AND b.id = ?';
            $params[] = $branch;
        }
        $query = $this->db->prepare($sql.' ORDER BY c.name, b.name');
        $query->execute($params);
        return $query->fetchAll();
    }

    public function home(): Response
    {
        $assignments=$this->assignments();
        if ($assignments) {
            // Prefer the existing Sudan workspace when upgrading an installation with legacy branches.
            $workspace=$assignments[0];
            foreach ($assignments as $item) {
                if (strcasecmp(trim($item['name']),'Sudan')===0) { $workspace=$item; break; }
            }
            return $this->redirectToRoute('office',['branch'=>$workspace['id'],'section'=>'dashboard']);
        }
        return $this->render('branches.html.twig',['branches'=>[]]);
    }

    public function branch(int $branch, Request $request): Response
    {
        $assignment = $this->assignments($branch)[0] ?? throw $this->createNotFoundException();
        $accounts = $this->db->prepare('SELECT id, name, kind FROM cash_accounts
            WHERE branch_id = ? AND company_id = ? AND currency = ? ORDER BY name');
        $accounts->execute([$branch, $assignment['company_id'], $assignment['reporting_currency']]);
        $old = $request->getSession()->getFlashBag()->get('transfer_input')[0] ?? [];
        return $this->render('branch.html.twig', ['assignment' => $assignment, 'accounts' => $accounts->fetchAll(),
            'old' => $old, 'request_key' => (string) \Symfony\Component\Uid\Uuid::v4()]);
    }

    public function transfer(int $branch, Request $request): Response
    {
        $assignment = $this->assignments($branch)[0] ?? throw $this->createNotFoundException();
        if ($assignment['role'] !== 'cashier') {
            throw $this->createAccessDeniedException();
        }
        $input = $request->request;
        $source = filter_var($input->get('source'), FILTER_VALIDATE_INT);
        $destination = filter_var($input->get('destination'), FILTER_VALIDATE_INT);
        $amount = (string)$input->get('amount');
        $key = (string)$input->get('request_key');
        if (!$this->isCsrfTokenValid('staff', $input->get('_token'))) {
            // Keep what was typed; the form gets a fresh token and nothing was posted.
            $this->addFlash('error', 'This form expired. Check the details and submit again.');
            $this->addFlash('transfer_input', compact('source', 'destination', 'amount'));
            return $this->redirectToRoute('branch', ['branch' => $branch]);
        }
        try {
            if ($source && $source === $destination) {
                throw new \InvalidArgumentException('Choose two different accounts.');
            }
            $amount = Amounts::clean($amount);
            if (!$source || !$destination || strlen($amount) > 40 ||
                !preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $key)) {
                throw new \InvalidArgumentException('Select two different accounts and enter a valid amount.');
            }
            $accounts = $this->db->prepare('SELECT COUNT(*) FROM cash_accounts
                WHERE branch_id = ? AND company_id = ? AND currency = ? AND id IN (?, ?)');
            $accounts->execute([$branch, $assignment['company_id'], $assignment['reporting_currency'], $source, $destination]);
            if ((int)$accounts->fetchColumn() !== 2) {
                throw new HttpException(422, 'Invalid accounts.');
            }
            $id = (new Treasury($this->db))->transferForStaff($assignment['user_id'], $assignment['company_id'],
                $branch, $source, $destination, $assignment['reporting_currency'], $amount, $key);
            $this->addFlash('message', "Transfer #$id recorded.");
        } catch (BranchAccessDenied $e) {
            throw $this->createAccessDeniedException();
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            $this->addFlash('transfer_input', compact('source', 'destination', 'amount'));
        }
        return $this->redirectToRoute('branch', ['branch' => $branch]);
    }
}
