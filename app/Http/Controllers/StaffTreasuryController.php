<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\BranchAccessDenied;
use App\Accounting\Treasury;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class StaffTreasuryController extends AbstractController
{
    public function __construct(private PDO $db) {}

    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }
        return $this->render('login.html.twig', ['failed' => $auth->getLastAuthenticationError() !== null,
            'email' => $auth->getLastUsername()]);
    }

    public function logout(): never
    {
        throw new \LogicException('The firewall handles sign-out.');
    }

    public function health(): Response
    {
        return new Response('OK');
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
        return $this->render('branches.html.twig', ['branches' => $this->assignments()]);
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
        if (!$this->isCsrfTokenValid('staff', $input->get('_token'))) {
            throw new HttpException(419, 'Form expired.');
        }
        $source = filter_var($input->get('source'), FILTER_VALIDATE_INT);
        $destination = filter_var($input->get('destination'), FILTER_VALIDATE_INT);
        $amount = (string)$input->get('amount');
        $key = (string)$input->get('request_key');
        try {
            if (!$source || !$destination || $source === $destination || strlen($amount) > 40 ||
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
