<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AppController extends AbstractController
{
    #[Route('/', name: 'app_app')]
    public function index(Request $request, GoogleController $googleController, ClientRegistry $clientRegistry): Response
    {

        foreach (['google', 'github'] as $service) {
            $redirect = $googleController->getRedirect($service);
            parse_str($queryString = parse_url($targetUrl = $redirect->getTargetUrl(), PHP_URL_QUERY), $array);
            $redirectUri = $array['redirect_uri'];
            $productionRedirctUri = str_replace($request->getSchemeAndHttpHost(),
                $this->getParameter('production_url'),
                $redirectUri
            );
            $services[$service] = [
                'redirectUri' => $redirectUri,
                'productionRedirectUri' => $productionRedirctUri,
                'targetUrl' => $redirect->getTargetUrl(),
                'targetInfo' => parse_url($redirect->getTargetUrl()),
                'query' => $array,
                'clientId' => $array['client_id'],
                'projectId' => $this->getParameter($service . '_project_id'),
                'service_apps_url' => match ($service) {
                    'github' => 'https://github.com/settings/developers',
                    'google' => 'https://console.cloud.google.com/apis/credentials'
                }
            ];
        }
        return $this->render('app/index.html.twig', [
            'productionUrl' => $this->getParameter('production_url'),
            'services' => $services,
            'projectId' => $this->getParameter('google_project_id'),
        ]);
    }
}
