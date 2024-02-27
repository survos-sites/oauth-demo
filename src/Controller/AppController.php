<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AppController extends AbstractController
{
    #[Route('/', name: 'app_app')]
    public function index(GoogleController $googleController, ClientRegistry $clientRegistry): Response
    {
        $redirect = $googleController->getRedirect();

        parse_str($queryString = parse_url($targetUrl = $redirect->getTargetUrl(), PHP_URL_QUERY), $array);
//        dd($array, $queryString, $targetUrl);
        return $this->render('app/index.html.twig', [
            'targetUrl' => $redirect->getTargetUrl(),
            'targetInfo' => parse_url($redirect->getTargetUrl()),
            'query' => $array,
            'clientId' => $array['client_id'],
            'projectId' => $this->getParameter('google_project_id'),
            'controller_name' => 'AppController',
        ]);
    }
}
