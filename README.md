
There's a quirk with make:user if the database is initially sqlite and then is switched to postgres.

So this demo uses postgres, you can install it with docker easily.  Whatever postgres you use, 
make sure to add the appropriate DATABASE_URL to .env.local 

```bash
sudo docker run --rm --name pg-docker -e POSTGRES_PASSWORD=docker -d -p 5434:5432 -v $HOME/docker/volumes/postgres16:/var/lib/postgresql/data postgres:16
```

Install the app with a traditional email-based registration/login.

```bash
symfony new --webapp oauth-demo && cd oauth-demo
# change
echo "DATABASE_URL=postgresql://postgres:docker@127.0.0.1:5434/auth-demo?serverVersion=16&charset=utf8" > .env.local

bin/console doctrine:database:create
composer config extra.symfony.allow-contrib true
composer config repositories.tac_oauth '{"type": "vcs", "url": "git@github.com:tacman/oauth2-client-bundle.git"}'

composer require knpuniversity/oauth2-client-bundle:dev-tac league/oauth2-github
bin/console importmap:require bootstrap
echo "import 'bootstrap/dist/css/bootstrap.min.css'" >> assets/app.js

symfony console make:controller AppController
sed -i "s|/app|/|" src/Controller/AppController.php 

bin/console make:user --is-entity --identity-property-name=email --with-password User -n

echo "github_id,string,80,yes," | sed "s/,/\n/g"  | bin/console make:entity User
echo "yes,no,yes,14" | sed "s/,/\n/g"  | bin/console make:registration

bin/console make:migration
bin/console doctrine:migration:migrate -n

echo "1,AppAuthenticator,,," | sed "s/,/\n/g"  | bin/console make:auth
sed  -i "s|some_route|app_app|" src/Security/AppAuthenticator.php
sed  -i "s|// return new|return new|" src/Security/AppAuthenticator.php
sed  -i "s|throw new|//throw new|" src/Security/AppAuthenticator.php


echo '' > templates/social_media_login.html.twig
cat <<'EOF' > templates/app/index.html.twig
{% extends 'base.html.twig' %}
{% block body %}
    <div>
        <a href="{{ path('app_app') }}">Home</a>
    </div>
    {% if is_granted('IS_AUTHENTICATED_FULLY') %}
        <a class="btn btn-primary" href="{{ path('app_logout') }}">Logout {{ app.user.email }} </a>
    {% else %}
        <a class="btn btn-primary" href="{{ path('app_register') }}">Register</a>
        <a class="btn btn-secondary" href="{{ path('app_login') }}">Login</a>
    {% endif %}
    {{ include('social_media_login.html.twig') }}
{% endblock %}
EOF

symfony proxy:domain:attach oauth-demo
symfony server:start -d
symfony open:local --path=/register
```

## Configure the Github OAuth

Now that the traditional login/registration forms work, let's add the github config and env vars. 

```bash
cat <<'EOF' > config/packages/knpu_oauth2_client.yaml
knpu_oauth2_client:
    clients:
        # an instance of: KnpU\OAuth2ClientBundle\Client\Provider\GithubClient
        # composer require league/oauth2-github
        github:
            # must be "github" - it activates that type!
            type: github
            # add and configure client_id and client_secret in parameters.yml
            client_id: '%env(OAUTH_GITHUB_CLIENT_ID)%'
            client_secret: '%env(OAUTH_GITHUB_CLIENT_SECRET)%'
            # a route name you'll create
            redirect_route: connect_github_check
            redirect_params: { clientKey: github }  # MUST match the client key above

            # whether to check OAuth2 "state": defaults to true
            # use_state: true
EOF
cat << 'EOF' >> .env
OAUTH_GITHUB_CLIENT_ID=
OAUTH_GITHUB_CLIENT_SECRET=
EOF

cat << 'EOF' >> .env.local
OAUTH_GITHUB_CLIENT_ID=
OAUTH_GITHUB_CLIENT_SECRET=
EOF

```

## Generate the App and Keys on Github

Now configure the app on github.  This step now requires 2FA, and is the slowest part
of this tutorial!  Once you're in, go to https://github.com/settings/developers and click on *New OAuth App*

![img.png](img.png)

Fill out the form, paying careful attention to the redirect url https://oauth-demo.wip/connect/github/check, which we'll define in the controller

Generate the key:

![img_1.png](img_1.png)

Open .env.local and set the key and client id

Now create the controller that handles the workflow.  In this case, we're going to create a user 
automatically and set the password to the github username. A real application would handle this better.

## Create the Github Controller 

```bash
cat << 'EOF' > src/Controller/GithubController.php
<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\AppAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class GithubController extends AbstractController
{

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserAuthenticatorInterface $userAuthenticator,
        private AppAuthenticator $authenticator,
        private UserPasswordHasherInterface $userPasswordHasher
    )
    {
    }
    /**
     * Link to this controller to start the "connect" process
     */
    #[Route('/connect/github', name: 'connect_github_start')]
    public function connect(ClientRegistry $clientRegistry): Response
    {
        // will redirect to Github!
        $redirect = $clientRegistry
            ->getClient('github') // key used in config/packages/knpu_oauth2_client.yaml
            ->redirect([
                 'email' // the scopes you want to access
            ]);
        # hack for not returning https
        $redirect->setTargetUrl(str_replace('http%3A', 'https%3A', $redirect->getTargetUrl()));

        return $redirect;
    }

    /**
     * After going to Github, you're redirected back here
     * because this is the "redirect_route" you configured
     * in config/packages/knpu_oauth2_client.yaml and in the Github App page
     */
    #[Route('/connect/github/check', name: 'connect_github_check')]
    public function connectCheckAction(Request $request, ClientRegistry $clientRegistry): Response
    {

        /** @var \KnpU\OAuth2ClientBundle\Client\Provider\GithubClient $client */
        $client = $clientRegistry->getClient('github');

        try {
            // the exact class depends on which provider you're using
            /** @var \League\OAuth2\Client\Provider\GithubResourceOwner $user */
            $accessToken = $client->getAccessToken();
            $oAuthUser = $client->fetchUserFromToken($accessToken);
            $email = $oAuthUser->getEmail();

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
                ->setGithubId($accessToken);
            $this->entityManager->flush();

            $this->userAuthenticator->authenticateUser($user, $this->authenticator, $request);

        } catch (IdentityProviderException $e) {
            // something went wrong!
            // probably you should return the reason to the user
            dd($e->getMessage());
        }

        return $this->redirectToRoute('app_app');
    }
}
EOF
```

```bash
cat << 'EOF' > templates/social_media_login.html.twig
<a class="btn btn-danger" href="{{ path('connect_github_start') }}">Login with Github</a>
EOF
symfony open:local --path=/connect/github
```


## Deployment

Often for deployment, we need a real database, since sqlite is on a disk that might not be writable.

```bash
```

