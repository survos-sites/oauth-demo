

```bash
symfony new --webapp oauth-demo && cd oauth-demo
echo "DATABASE_URL=postgresql://postgres:docker@127.0.0.1:5432/auth-demo?serverVersion=16&charset=utf8" > .env.local
bin/console doctrine:database:create
composer config extra.symfony.allow-contrib true
composer require knpuniversity/oauth2-client-bundle league/oauth2-github
bin/console make:user --is-entity --identity-property-name=email --with-password User -n

echo "github_id,string,80,yes," | sed "s/,/\n/g"  | bin/console make:entity User
echo "yes,no,yes" | sed "s/,/\n/g"  | bin/console make:registration

bin/console make:migration
bin/console doctrine:migration:migrate -n

echo "1,AppAuthenticator,,," | sed "s/,/\n/g"  | bin/console make:auth

symfony console make:controller AppController
sed -i "s|/app|/|" src/Controller/AppController.php 

cat <<'EOF' > templates/app/index.html.twig
{% extends 'base.html.twig' %}
{% block body %}
Hello, 
{{ app.user ? app.user : 'visitor }}

<a href="{{ path('app_register') }}">Register</a>
<a href="{{ path('app_login') }}">Login</a>
<a href="{{ path('app_logout') }}">Logout</a>
{% endblock %}
EOF

symfony proxy:domain:attach auth-demo
symfony server:start -d
symfony open:local --path=/register

```
