<?php

namespace App\Controller\Admin;

use App\Repository\DomainRepository;
use App\Repository\ReceivedEmailRepository;
use App\Repository\TemporaryEmailBoxRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private DomainRepository $domainRepository,
        private TemporaryEmailBoxRepository $temporaryEmailBoxRepository,
        private ReceivedEmailRepository $receivedEmailRepository,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'activeDomainsCount' => $this->domainRepository->countOfActiveDomains(),
            'temporaryEmailBoxCount' => $this->temporaryEmailBoxRepository->count(),

            'receivedEmailsWithEmailBoxCount' => $this->receivedEmailRepository->countWithTemporaryEmailBox(),
            'receivedEmailsWithoutEmailBoxCount' => $this->receivedEmailRepository->countWithoutTemporaryEmailBox(),

            'readEmails' => $this->receivedEmailRepository->countReadEmails(),
            'unreadEmails' => $this->receivedEmailRepository->countUnreadEmails(),
            'emailsWithReadSubjects' => $this->receivedEmailRepository->countEmailWithReadSubjects(),

            'oldestReceivedEmailAt' => $this->receivedEmailRepository->findOneBy([], ['createdAt' => 'ASC']),
            'newestReceivedEmailAt' => $this->receivedEmailRepository->findOneBy([], ['createdAt' => 'DESC']),

            'topCountriesByReceivedEmails' => $this->receivedEmailRepository->findTopCountriesByReceivedEmailCount(),
            'topCountriesByEmailBoxes' => $this->temporaryEmailBoxRepository->findTopCountriesByEmailBoxCount(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->disableDarkMode()
            ->setFaviconPath('brand/mail4u-favicon.ico')
            ->generateRelativeUrls()
            ->setTitle('<img class="mail4u-admin-logo" src="/brand/mail4u-logo-original.png" alt="Mail4u"><span class="mail4u-admin-caption">Administração</span>');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('/brand/mail4u.css')
            ->addCssFile('/brand/mail4u-admin.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Painel', 'fas fa-home');
        yield MenuItem::linkTo(DomainCrudController::class, 'Domínios', 'fas fa-globe');
        yield MenuItem::section('Dados de usuários');
        yield MenuItem::linkTo(UserCrudController::class, 'Usuários', 'fas fa-user');
        yield MenuItem::linkTo(TemporaryEmailBoxCrudController::class, 'Caixas de e-mail', 'fas fa-paper-plane');
        yield MenuItem::linkTo(ReceivedEmailCrudController::class, 'E-mails recebidos', 'fas fa-envelope');
    }
}
