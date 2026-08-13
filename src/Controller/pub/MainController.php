<?php

namespace App\Controller\pub;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Psr\Log\LoggerInterface;

class MainController extends AbstractController
{
    public function __construct(private LoggerInterface $logger)
    {
    }
    #[Route('/', name: 'pub_home')]
    public function home(): Response
    {
        return $this->render('pub/main/home.html.twig', [

        ]);
    }

    #[Route('/contato', name: 'pub_contact', methods: ['GET'])]
    public function contact(): Response
    {
        return $this->redirectToRoute('pub_home', ['_fragment' => 'contato']);
    }

    #[Route('/contato', name: 'pub_contact_post', methods:["POST"])]
    public function contactPost(Request $request, ParameterBagInterface $parameters, MailerInterface $mailer, Filesystem $filesystem): Response
    {
        $redirect = $this->redirectToRoute('pub_home', ['_fragment' => 'contato']);

        if (!$this->isCsrfTokenValid('contact_form', (string) $request->request->get('_token'))) {
            $this->addFlash('contact_f', 'Sua sessão expirou. Atualize a página e tente novamente.');
            return $redirect;
        }

        $name = trim((string) $request->request->get('name'));
        $email = trim((string) $request->request->get('email'));
        $phone = trim((string) $request->request->get('phone'));
        $message = trim((string) $request->request->get('message'));

        if (!$name || !$email || !$message) {
            $this->addFlash('contact_f', 'Preencha nome, e-mail e mensagem para continuar.');
            return $redirect;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('contact_f', 'Informe um e-mail válido.');
            return $redirect;
        }

        $context = [
            'name' => $name,
            'sender_email' => $email,
            'phone' => $phone ?: '—',
            'message' => $message,
            'submitted_at' => new \DateTimeImmutable('now'),
        ];

        try{
            $email = (new TemplatedEmail())
                ->from(new Address($parameters->get('emailFrom'), 'Site Base'))
                ->to($parameters->get('emailContactTo'))
                ->subject(sprintf('Novo contato: %s', $name))
                ->htmlTemplate('email/contact.html.twig')
                ->context($context)
            ;

            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('contact_s', 'Mensagem registrada (modo offline). Vamos processá-la em breve.');
            $this->logException($e);
            $fallbackDir = $this->getParameter('kernel.project_dir').'/var/emails';
            $filesystem->mkdir($fallbackDir);
            $filesystem->dumpFile(
                sprintf('%s/contact-%s.html', $fallbackDir, (new \DateTimeImmutable())->format('YmdHis')),
                $this->renderView('email/contact.html.twig', $context)
            );
            return $redirect;
        } catch(\Throwable $e){
            $this->logException($e);
            $this->addFlash('contact_f', 'Houve um erro ao enviar seus dados. '.$e->getMessage());
            return $redirect;
        }
        $this->addFlash('contact_s', 'Sua mensagem foi enviada.');
        return $redirect;
    }

    private function logException(\Throwable $exception): void
    {
        $this->logger->error('Erro ao processar contato: '.$exception->getMessage(), [
            'exception' => $exception,
        ]);
    }
}
