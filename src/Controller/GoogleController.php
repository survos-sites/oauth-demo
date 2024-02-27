<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\AppAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\Google;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class GoogleController extends AbstractController
{

    public const SCOPES = [
        'google' => [],
        'github' => ['user','user:email','repo'],
        'facebook' => ['public_profile', 'email'],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserAuthenticatorInterface $userAuthenticator,
        private AppAuthenticator $authenticator,
        private UserPasswordHasherInterface $userPasswordHasher,
        private ClientRegistry $clientRegistry
    )
    {
    }
    /**
     * Link to this controller to start the "connect" process
     */
    #[Route(path: '/oauth/connect/service/{service}', name: 'oauth_login',  methods:['GET'])]
    public function connect( string $service, ClientRegistry $clientRegistry ): RedirectResponse
    {
        if ( !in_array($service, array_keys(self::SCOPES), TRUE) )
        {
            throw $this->createNotFoundException() ;
        }

        // $clientRegistry = $this->get('knpu.oauth2.registry');
        $redirect = $clientRegistry
            ->getClient($service) // the name use in config/packages/knpu_oauth2_client.yaml
            ->redirect( self::SCOPES[$service], [] ) ;  // 'public_profile', 'email' ,  the scopes you want to access
        $targetUrl = $redirect->getTargetUrl();
        $redirect->setTargetUrl(str_replace('http%3A', 'https%3A', $targetUrl));
        return $redirect;
    }

    public function getRedirect(): RedirectResponse
    {
        // will redirect to Google!
        $redirect = $this->clientRegistry
            ->getClient('google') // key used in config/packages/knpu_oauth2_client.yaml
            ->redirect([]);
        $targetUrl = $redirect->getTargetUrl();
        $redirect->setTargetUrl(str_replace('http%3A', 'https%3A', $targetUrl));
        return $redirect;

    }

    /**
     * After going to Google, you're redirected back here
     * because this is the "redirect_route" you configured
     * in config/packages/knpu_oauth2_client.yaml and in the Google App page
     */
    #[Route('/oauth/check/{service}', name: 'auth_oauth_check',  methods:['GET','POST'])]
    public function connectCheckAction(string $service, Request $request, ClientRegistry $clientRegistry): Response
    {
        /** @var GoogleClient $client */
        $client = $clientRegistry->getClient($service);

            // the exact class depends on which provider you're using
            /** @var Google $user */
            $accessToken = $client->getAccessToken();
            $oAuthUser = $client->fetchUserFromToken($accessToken);
            $email = $oAuthUser->getEmail();
        try {

        } catch (IdentityProviderException $e) {
            // something went wrong!
            // probably you should return the reason to the user
            return new JsonResponse([
                'service' => $service,
                'accessToken' => $accessToken??null,
                'message' => $e->getMessage(),
                    'queryParams' => $request->query->all(),
                ]
            );
            dd($e->getMessage());
        }

        if (!$user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) {
            $user = (new User())
                ->setEmail($email);
            $this->entityManager->persist($user);
        }
        // better is to redirect to a page requiring the user to set/change their password, or allow null passwords.
        $plaintextPassword = $oAuthUser->getId();
        $hashedPassword = $this->userPasswordHasher->hashPassword(
            $user,
            $plaintextPassword
        );
        $user
            ->setPassword($hashedPassword)
            ->setGoogleId($accessToken);
        $this->entityManager->flush();

        $this->userAuthenticator->authenticateUser($user, $this->authenticator, $request);


        return $this->redirectToRoute('app_app');
    }
}
