<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\AppAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
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
    #[Route('/connect/google', name: 'connect_google_start')]
    public function connect(): Response
    {
        return $this->getRedirect();
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
    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function connectCheckAction(Request $request, ClientRegistry $clientRegistry): Response
    {

        $service =  $request->attributes->all()['service'];

        /** @var \KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient $client */
        $client = $clientRegistry->getClient('google');

        try {
            // the exact class depends on which provider you're using
            /** @var Google $user */
            $accessToken = $client->getAccessToken();
            $oAuthUser = $client->fetchUserFromToken($accessToken);
            $email = $oAuthUser->getEmail();

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
