# Politique de sécurité

## Versions prises en charge

Seule la dernière version publiée reçoit des correctifs de sécurité.

| Version | Prise en charge |
| ------- | --------------- |
| 1.2.x   | Oui             |
| < 1.2   | Non             |

## Signaler une vulnérabilité

Merci de **ne pas ouvrir d'issue publique** pour une faille de sécurité.

Utilisez l'onglet **Security → Report a vulnerability** du dépôt GitHub, ou
écrivez à <contact@g2rd.fr>. Une réponse est apportée sous 72 heures.

Merci d'inclure :

- la version du plugin et celle de WordPress ;
- les étapes de reproduction ;
- l'impact constaté.

## Surface d'attaque

Le plugin est volontairement minimal. Les points sensibles sont les suivants,
et chacun est couvert par la CI.

### Endpoint REST public `g2rd-contact/v1/click`

Accessible sans authentification, par conception : les visiteurs qui cliquent
un lien de contact ne sont pas connectés. Un nonce serait par ailleurs
inopérant sur des pages servies depuis un cache (LiteSpeed, WP Rocket), où il
arriverait périmé et rejetterait des clics légitimes.

Les protections en place :

- liste blanche stricte des valeurs acceptées (`tel`, `mail`), appliquée deux
  fois — par le schéma REST puis à nouveau dans le gestionnaire ;
- limitation de débit par IP, avec sortie **avant** toute écriture lorsque le
  plafond est atteint : un flood coûte une lecture, jamais une écriture ;
- l'endpoint n'expose aucune donnée et n'incrémente qu'un compteur.

### Résolution de l'adresse IP

Seul `REMOTE_ADDR` est lu. Les en-têtes de type `X-Forwarded-For` sont ignorés
par défaut : fournis par l'appelant, ils permettraient de contourner la
limitation de débit en changeant de valeur à chaque requête. Derrière un CDN,
le filtre `g2rd_contact_client_ip` permet de brancher l'en-tête de confiance du
CDN — c'est un choix explicite de l'administrateur, pas un défaut.

L'IP n'est jamais stockée : seule son empreinte sert de clé à un transient
d'une minute.

### Mises à jour automatiques

Le plugin interroge l'API GitHub et ne retient qu'un ZIP attaché à une release,
servi par `github.com` en HTTPS. Le zipball généré automatiquement par GitHub
est refusé : sa structure de dossier installerait l'extension sous un mauvais
nom. Le changelog affiché dans l'administration est **intégralement échappé**
avant mise en forme, jamais interprété comme du HTML.

## Contrôles automatisés

Chaque `push` et chaque `pull request` déclenchent, de façon **bloquante** :

| Contrôle                | Portée                                                    |
| ----------------------- | --------------------------------------------------------- |
| PHPCS WordPress         | Standards WordPress, dont les sniffs `WordPress.Security` |
| PHPCS Security Audit    | SAST orienté OWASP                                        |
| Semgrep                 | SAST généraliste : PHP, XSS, secrets                      |
| PHPStan niveau max      | Analyse statique la plus stricte                          |
| PHPCompatibility        | Compatibilité PHP 8.0 à 8.4                               |
| WordPress Plugin Check  | Contrôles officiels sécurité et performance               |
| `composer audit`        | Avis de sécurité sur les dépendances figées               |
| actionlint + zizmor     | Sécurité des workflows eux-mêmes                          |

L'audit des dépendances tourne également **chaque lundi**, afin qu'un avis
publié après le dernier commit soit malgré tout détecté.

Les actions GitHub sont épinglées par **SHA de commit**, et non par étiquette :
une étiquette peut être redirigée vers un autre commit, ce qui constitue un
vecteur classique de compromission de chaîne d'approvisionnement. Dependabot
maintient ces empreintes à jour.
