
There's a quirk with make:user if the database is initially sqlite and then is switched to postgres.

So this demo uses postgres.  It easy to install with docker.

```bash
symfony new --webapp oauth-demo && cd oauth-demo
bin/console doctrine:database:create
composer config extra.symfony.allow-contrib true
composer require knpuniversity/oauth2-client-bundle league/oauth2-github
bin/console importmap:require bootstrap
echo "import 'bootstrap/dist/css/bootstrap.min.css'" >> assets/app.js

echo "DATABASE_URL=postgresql://postgres:docker@127.0.0.1:5432/auth-demo?serverVersion=16&charset=utf8" > .env.local
bin/console d:d:create

bin/console make:user --is-entity --identity-property-name=email --with-password User -n

echo "github_id,string,80,yes," | sed "s/,/\n/g"  | bin/console make:entity User
echo "yes,no,yes" | sed "s/,/\n/g"  | bin/console make:registration

bin/console make:migration
bin/console doctrine:migration:migrate -n

echo "1,AppAuthenticator,,," | sed "s/,/\n/g"  | bin/console make:auth
sed  -i "s|some_route|app_app|" src/Security/AppAuthenticator.php
sed  -i "s|// return new|return new|" src/Security/AppAuthenticator.php
sed  -i "s|throw new|//throw new|" src/Security/AppAuthenticator.php

symfony console make:controller AppController
sed -i "s|/app|/|" src/Controller/AppController.php 

touch templates/social_media_login.html.twig
cat <<'EOF' > templates/app/index.html.twig
{% extends 'base.html.twig' %}
{% block body %}
Hello, 
{{ app.user ? app.user : 'visitor' }}

<a href="{{ path('app_register') }}">Register</a>
<a href="{{ path('app_login') }}">Login</a>
<a href="{{ path('app_logout') }}">Logout</a>
<a href="{{ path('app_app') }}">Home</a>
{% endblock %}
EOF

symfony proxy:domain:attach oauth-demo
symfony server:start -d
symfony open:local --path=/register

```

Now that the traditional login/registration forms work, let's add github. 

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

bin/console debug:route connect_github_check
```

Now get the all-important keys!  Go to https://github.com/settings/developers and click on New OAuth App

![img.png](img.png)

Fill out the form, paying careful attention to the redirect url https://oauth-demo.wip/connect/github/check

Generate the key:

![img_1.png](img_1.png)

Add add it to .env.local
```bash
cat << 'EOF' >> .env
OAUTH_GITHUB_CLIENT_ID=
OAUTH_GITHUB_CLIENT_SECRET=
EOF

cat << 'EOF' >> .env.local
OAUTH_GITHUB_CLIENT_ID=
OAUTH_GITHUB_CLIENT_SECRET=
EOF

```

Open .env.local and set the key and client id


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

