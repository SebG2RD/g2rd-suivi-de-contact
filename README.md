# G2RD — Suivi des clics de contact

[![CI](https://github.com/SebG2RD/g2rd-suivi-de-contact/actions/workflows/ci.yml/badge.svg)](https://github.com/SebG2RD/g2rd-suivi-de-contact/actions/workflows/ci.yml)
[![Securite](https://github.com/SebG2RD/g2rd-suivi-de-contact/actions/workflows/security.yml/badge.svg)](https://github.com/SebG2RD/g2rd-suivi-de-contact/actions/workflows/security.yml)

Extension WordPress qui compte les clics sur les liens téléphone et e-mail.
Elle envoie un événement vers Google Analytics et tient un compteur local,
affiché dans le tableau de bord et différencié entre téléphone et e-mail.

- **Requiert :** WordPress 6.6+, PHP 8.0+
- **Licence :** EUPL-1.2

## Fonctionnement

Un écouteur de clic est posé en **phase de capture** sur `document` : un
`stopPropagation()` émis par un bloc Modal ou Slider ne peut donc pas masquer
le clic. Les liens `tel:` et `mailto:` sont détectés, ainsi que les adresses
obfusquées par Cloudflare « Email Address Obfuscation ».

L'événement part vers `gtag()` s'il existe, sinon vers `dataLayer` (Google Tag
Manager). Le comptage local passe par `sendBeacon`, qui survit à la navigation
déclenchée par `tel:` — un `fetch()` serait interrompu.

## Mises à jour automatiques

Le plugin n'est pas publié sur wordpress.org : il se met à jour depuis les
**releases GitHub** de ce dépôt.

L'en-tête `Update URI` déclenche le filtre `update_plugins_github.com`
(WordPress 5.8+), mécanisme officiel du cœur. Un site installé voit donc la
nouvelle version dans **Extensions** et dans **Tableau de bord → Mises à jour**,
avec installation en un clic, exactement comme une extension du répertoire
officiel.

La réponse de l'API GitHub est mise en cache 6 heures — l'API non authentifiée
est plafonnée à 60 requêtes/heure/IP, et un appel à chaque contrôle épuiserait
ce quota au point de faire disparaître la mise à jour par intermittence. Le
bouton « Vérifier à nouveau » vide ce cache immédiatement.

### Publier une version

```bash
# 1. Mettre à jour l'en-tête « Version » dans g2rd-suivi-contact.php
# 2. Committer, puis poser le tag correspondant
git tag v1.2.1
git push origin v1.2.1
```

Le workflow de release vérifie que le tag et l'en-tête du plugin concordent —
sans quoi les sites téléchargeraient la mise à jour puis la reproposeraient en
boucle —, rejoue l'ensemble des contrôles, construit le ZIP et publie la
release. Les sites la détectent au contrôle suivant.

## Développement

```bash
composer install

composer run check           # tous les contrôles de la CI, en une commande
composer run phpcs           # standards WordPress
composer run phpcs:fix       # correction automatique
composer run phpcs:security  # audit de sécurité OWASP
composer run phpstan         # analyse statique, niveau max
```

## Sécurité

La CI est **bloquante** : aucune étape n'utilise `continue-on-error`. Le détail
des contrôles et de la surface d'attaque est documenté dans
[SECURITY.md](SECURITY.md).
