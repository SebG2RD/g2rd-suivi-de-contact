<?php

/**
 * Plugin Name:       G2RD — Suivi des clics de contact
 * Plugin URI:        https://g2rd.fr
 * Description:       Compte les clics sur les liens téléphone et e-mail. Envoie un événement vers Google Analytics et tient un compteur local affiché dans le tableau de bord, différencié entre téléphone et e-mail.
 * Version:           1.2.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Sebastien GERARD
 * Author URI:        https://g2rd.fr
 * License:           EUPL-1.2
 * License URI:       https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * Text Domain:       g2rd-suivi-contact
 * Update URI:        https://github.com/SebG2RD/g2rd-suivi-de-contact
 *
 * Historique des versions
 *
 *   1.2.0 — Mises à jour automatiques depuis les releases GitHub : les sites
 *           installés voient la nouvelle version dans Tableau de bord →
 *           Mises à jour, comme pour une extension du répertoire officiel.
 *           Normalisation typée des compteurs et des entrées non fiables,
 *           pour permettre l'analyse statique au niveau maximal en CI.
 *   1.1.0 — Détecte les liens e-mail obfusqués par Cloudflare « Email Address
 *           Obfuscation » et les plugins anti-spam équivalents : sans cela les
 *           clics e-mail n'étaient jamais comptés, alors que les clics
 *           téléphone fonctionnaient, tel: n'étant jamais obfusqué.
 *           Comparaison du schéma sur la chaîne entière plutôt qu'un
 *           slice(0, 7), et trim() du href.
 *   1.0.1 — Résolution d'IP filtrable pour les sites derrière un CDN, et
 *           structure de données défensive contre une option corrompue.
 *   1.0.0 — Version initiale.
 *
 * @package G2RD_Suivi_Contact
 */

// Sécurité : empêcher l'accès direct.
if (! defined('ABSPATH')) {
	exit;
}

/** Option stockant les compteurs. Chargée à la demande, jamais en autoload. */
const G2RD_CONTACT_CLICKS_OPTION = 'g2rd_contact_clicks';

/** Nombre de mois d'historique conservés. */
const G2RD_CONTACT_CLICKS_MONTHS = 12;

/**
 * Types de contact suivis. Source unique de vérité : sert à la fois d'enum du
 * schéma REST, de liste blanche à la revérification serveur et de squelette de
 * la structure de compteurs. Ajouter un type ici suffit à le propager partout.
 */
const G2RD_CONTACT_TYPES = ['tel', 'mail'];

/** Clics acceptés par IP et par minute. Très au-dessus d'un usage humain. */
const G2RD_CONTACT_RATE_LIMIT = 20;

/**
 * Identifiant de l'extension : nom du dossier dans wp-content/plugins, et slug
 * utilisé par l'API des extensions. Le ZIP de release crée toujours ce dossier.
 * Il diffère volontairement du nom du dépôt GitHub, plus verbeux.
 */
const G2RD_CONTACT_SLUG = 'g2rd-suivi-contact';

/** Dépôt GitHub servant les mises à jour, au format « proprietaire/depot ». */
const G2RD_CONTACT_REPO = 'SebG2RD/g2rd-suivi-de-contact';

/** Page publique du dépôt, affichée dans la fiche de l'extension. */
const G2RD_CONTACT_REPO_URL = 'https://github.com/' . G2RD_CONTACT_REPO;


/**
 * ---------------------------------------------------------------------------
 * 1. Suivi côté navigateur
 * ---------------------------------------------------------------------------
 */

/**
 * Injecte le script de suivi des clics téléphone et e-mail.
 *
 * Émet un événement « clic_telephone » ou « clic_email » vers Google Analytics,
 * et signale le clic au compteur local via l'endpoint REST du plugin.
 *
 * Compatible gtag.js ET Google Tag Manager : la méthode de chargement dépend du
 * plugin analytics installé sur le site, et seul dataLayer est garanti côté GTM.
 */
function g2rd_contact_track_clicks(): void
{
?>
	<script>
		(function() {
			// Garde anti-double-écoute : certains constructeurs de page
			// déclenchent wp_footer deux fois, ce qui compterait double.
			if (window.g2rdContactClicksBound) return;
			window.g2rdContactClicksBound = true;

			const endpoint = <?php echo wp_json_encode(esc_url_raw(rest_url('g2rd-contact/v1/click'))); ?>;

			/**
			 * Envoie l'événement à la couche analytics disponible.
			 * gtag.js si présent, sinon dataLayer (GTM), qui est défini de
			 * façon synchrone par le conteneur et met les événements en file.
			 *
			 * Le return est essentiel : sans lui, un site ayant les deux
			 * compterait chaque clic deux fois.
			 */
			const send = (name, params) => {
				if (typeof window.gtag === 'function') {
					window.gtag('event', name, params);
					return;
				}
				window.dataLayer = window.dataLayer || [];
				window.dataLayer.push({ event: name, ...params });
			};

			/** Tronque et normalise pour la limite GA4 de 100 caractères. */
			const clamp = (value) =>
				String(value || '').trim().replace(/\s+/g, ' ').slice(0, 100);

			// Phase de CAPTURE (3e argument à true) : un stopPropagation()
			// émis par un bloc Modal, Slider ou Sliding Panel empêcherait
			// l'événement d'atteindre document en phase de bouillonnement.
			document.addEventListener('click', (e) => {
				const target = e.target;
				if (!target || typeof target.closest !== 'function') return;

				const link = target.closest('a[href]');
				if (!link) return;

				// trim() : un href avec une espace de tête (fréquent quand le
				// lien est collé depuis un traitement de texte) échouerait.
				// Comparaison sur la chaîne entière en minuscules : les schémas
				// d'URL sont insensibles à la casse, « MAILTO: » est valide.
				const href = (link.getAttribute('href') || '').trim();
				const lower = href.toLowerCase();

				// Cloudflare « Email Address Obfuscation » (et plusieurs plugins
				// anti-spam) remplacent les liens mailto: par un lien encodé,
				// restauré en JavaScript. Sans ce cas, les clics e-mail ne sont
				// jamais détectés alors que les clics téléphone fonctionnent,
				// tel: n'étant lui jamais obfusqué.
				const isObfuscatedMail =
					lower.includes('/cdn-cgi/l/email-protection') ||
					link.hasAttribute('data-cfemail');

				const isPhone = lower.startsWith('tel:');
				const isMail = lower.startsWith('mailto:') || isObfuscatedMail;
				if (!isPhone && !isMail) return;

				send(isPhone ? 'clic_telephone' : 'clic_email', {
					link_url: clamp(href),
					link_text: clamp(link.textContent)
				});

				// Comptage local, qui alimente le widget du tableau de bord.
				// sendBeacon survit à la navigation déclenchée par tel: et
				// mailto:, contrairement à un fetch() classique qui serait
				// interrompu. Échoue silencieusement : le suivi ne doit jamais
				// gêner l'ouverture de l'appel ou du client de messagerie.
				if (navigator.sendBeacon) {
					navigator.sendBeacon(
						endpoint,
						new URLSearchParams({ type: isPhone ? 'tel' : 'mail' })
					);
				}
			}, true);
		})();
	</script>
<?php
}
add_action('wp_footer', 'g2rd_contact_track_clicks');


/**
 * ---------------------------------------------------------------------------
 * 2. Enregistrement côté serveur
 * ---------------------------------------------------------------------------
 */

/**
 * Déclare l'endpoint REST recevant les clics.
 */
function g2rd_contact_register_route(): void
{
	register_rest_route('g2rd-contact/v1', '/click', [
		'methods'  => 'POST',
		'callback' => 'g2rd_contact_record_click',

		/*
		 * Endpoint volontairement public : les visiteurs ne sont pas connectés.
		 *
		 * Aucun nonce n'est vérifié, et c'est délibéré : les pages sont souvent
		 * servies depuis un cache (LiteSpeed, WP Rocket…), or un nonce mis en
		 * cache devient périmé et rejetterait les clics légitimes. La protection
		 * repose donc sur :
		 *   - une liste blanche stricte des valeurs acceptées (enum) ;
		 *   - une limitation du débit par IP ;
		 *   - le fait que l'endpoint n'incrémente qu'un compteur : il n'expose
		 *     aucune donnée et ne modifie rien d'autre.
		 */
		'permission_callback' => '__return_true',
		'args' => [
			'type' => [
				'required' => true,
				'type'     => 'string',
				'enum'     => G2RD_CONTACT_TYPES,
			],
		],
	]);
}
add_action('rest_api_init', 'g2rd_contact_register_route');

/**
 * Résout l'adresse IP du visiteur, pour la limitation du débit uniquement.
 *
 * Utilise REMOTE_ADDR, la seule valeur non falsifiable par le client. Les
 * en-têtes du type X-Forwarded-For sont volontairement ignorés : ils sont
 * fournis par l'appelant, donc trivialement usurpables, et les lire par défaut
 * permettrait de contourner la limitation en changeant d'en-tête à chaque
 * requête.
 *
 * Derrière un CDN (Cloudflare…), REMOTE_ADDR vaut l'IP du proxy : le plafond
 * devient alors global au site. Si c'est ton cas, branche le filtre sur
 * l'en-tête de confiance de ton CDN, par exemple :
 *
 *     add_filter( 'g2rd_contact_client_ip', function () {
 *         return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ) );
 *     } );
 *
 * L'IP n'est jamais stockée : seule son empreinte sert de clé à un transient
 * d'une minute, à des fins de prévention d'abus.
 *
 * @return string Adresse IP, ou chaîne vide si indisponible.
 */
function g2rd_contact_client_ip(): string
{
	// Rien ne garantit le type des entrées de $_SERVER : le test is_string
	// écarte une valeur non textuelle avant tout nettoyage, et le nettoyage
	// reste dans la même expression que la lecture de la superglobale.
	$ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
		? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
		: '';

	// Un filtre tiers peut retourner n'importe quoi. On ne fait confiance qu'à
	// une chaîne : sinon on retombe sur l'IP résolue localement, ce qui laisse
	// la limitation de débit opérationnelle au lieu de la neutraliser.
	$filtered = apply_filters('g2rd_contact_client_ip', $ip);

	return is_string($filtered) ? $filtered : $ip;
}

/**
 * Charge les compteurs dans une structure dont la forme est garantie.
 *
 * L'option n'est écrite que par ce plugin, mais rien n'empêche un import, une
 * commande WP-CLI ou une autre extension de la corrompre. Toutes les lectures
 * passent donc par ici : les appelants manipulent une structure sûre au lieu de
 * redéfendre chaque accès, et un contenu inattendu est ramené à zéro plutôt que
 * de provoquer une erreur fatale dans le tableau de bord.
 *
 * @return array<string, array{total: int, months: array<string, int>}>
 */
function g2rd_contact_get_stats(): array
{
	$raw   = get_option(G2RD_CONTACT_CLICKS_OPTION, []);
	$raw   = is_array($raw) ? $raw : [];
	$stats = [];

	foreach (G2RD_CONTACT_TYPES as $type) {
		$entry  = $raw[$type] ?? null;
		$total  = 0;
		$months = [];

		if (is_array($entry)) {
			$total = is_numeric($entry['total'] ?? null) ? (int) $entry['total'] : 0;

			if (isset($entry['months']) && is_array($entry['months'])) {
				foreach ($entry['months'] as $month => $count) {
					// Seules les clés « AAAA-MM » sont conservées : une clé
					// arbitraire ferait grossir l'option sans jamais expirer.
					if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) && is_numeric($count)) {
						$months[$month] = (int) $count;
					}
				}
			}
		}

		$stats[$type] = ['total' => $total, 'months' => $months];
	}

	return $stats;
}

/**
 * Enregistre un clic dans les compteurs.
 *
 * @param WP_REST_Request $request Requête entrante.
 * @return WP_REST_Response
 */
function g2rd_contact_record_click(WP_REST_Request $request): WP_REST_Response
{
	$type = $request->get_param('type');

	// Défense en profondeur : l'enum du schéma filtre déjà, on revérifie.
	if (! is_string($type) || ! in_array($type, G2RD_CONTACT_TYPES, true)) {
		return new WP_REST_Response(['ok' => false], 400);
	}

	// Limitation du débit par IP et par minute : suffisant pour absorber un
	// script qui gonflerait le chiffre, sans gêner un usage humain.
	// Au-delà du plafond, on sort AVANT d'écrire : un flood coûte une lecture,
	// jamais une écriture supplémentaire.
	$key = 'g2rd_contact_rl_' . md5(g2rd_contact_client_ip());

	// get_transient() rend false si absent, et la valeur telle quelle sinon :
	// un cache objet corrompu ne doit pas remettre le compteur à zéro.
	$stored = get_transient($key);
	$hits   = is_numeric($stored) ? (int) $stored : 0;

	if ($hits >= G2RD_CONTACT_RATE_LIMIT) {
		return new WP_REST_Response(['ok' => false], 429);
	}
	set_transient($key, $hits + 1, MINUTE_IN_SECONDS);

	$stats = g2rd_contact_get_stats();

	// current_time() suit le fuseau du site : un clic le 31 à 23 h 30 en heure
	// française doit compter dans le mois courant, pas dans le suivant en UTC.
	$month = (string) current_time('Y-m');

	// Les deux types sont comptés séparément, jamais additionnés.
	$stats[$type]['total']          += 1;
	$stats[$type]['months'][$month] = ($stats[$type]['months'][$month] ?? 0) + 1;

	// Borne l'historique pour que l'option ne grossisse pas indéfiniment.
	if (count($stats[$type]['months']) > G2RD_CONTACT_CLICKS_MONTHS) {
		krsort($stats[$type]['months']);
		$stats[$type]['months'] = array_slice(
			$stats[$type]['months'],
			0,
			G2RD_CONTACT_CLICKS_MONTHS,
			true
		);
	}

	// autoload à false : ces compteurs ne servent que dans l'admin, inutile de
	// les charger à chaque page du site.
	update_option(G2RD_CONTACT_CLICKS_OPTION, $stats, false);

	// 204 « No Content » : le corps doit être vide, et sendBeacon() ignore de
	// toute façon la réponse. Renvoyer un corps ici serait contraire à la RFC.
	return new WP_REST_Response(null, 204);
}


/**
 * ---------------------------------------------------------------------------
 * 3. Affichage dans le tableau de bord
 * ---------------------------------------------------------------------------
 */

/**
 * Ajoute le widget au tableau de bord WordPress.
 */
function g2rd_contact_add_dashboard_widget(): void
{
	if (! current_user_can('manage_options')) {
		return;
	}

	wp_add_dashboard_widget(
		'g2rd_contact_clicks',
		__('Clics de contact', 'g2rd-suivi-contact'),
		'g2rd_contact_render_dashboard_widget'
	);
}
add_action('wp_dashboard_setup', 'g2rd_contact_add_dashboard_widget');

/**
 * Affiche le widget : mois en cours et cumul, par type de contact.
 */
function g2rd_contact_render_dashboard_widget(): void
{
	$stats = g2rd_contact_get_stats();
	$month = (string) current_time('Y-m');

	$lines = [
		'tel'  => __('Téléphone', 'g2rd-suivi-contact'),
		'mail' => __('E-mail', 'g2rd-suivi-contact'),
	];

	echo '<table style="width:100%;border-collapse:collapse;">';
	echo '<thead><tr>';
	echo '<th style="text-align:left;padding:.4em 0;">' . esc_html__('Contact', 'g2rd-suivi-contact') . '</th>';
	echo '<th style="text-align:right;padding:.4em 0;">' . esc_html__('Ce mois-ci', 'g2rd-suivi-contact') . '</th>';
	echo '<th style="text-align:right;padding:.4em 0;">' . esc_html__('Depuis le début', 'g2rd-suivi-contact') . '</th>';
	echo '</tr></thead><tbody>';

	foreach ($lines as $key => $label) {
		$total   = (int) ($stats[$key]['total'] ?? 0);
		$current = (int) ($stats[$key]['months'][$month] ?? 0);

		echo '<tr>';
		echo '<td style="padding:.4em 0;">' . esc_html($label) . '</td>';
		echo '<td style="text-align:right;padding:.4em 0;"><strong>' . esc_html((string) $current) . '</strong></td>';
		echo '<td style="text-align:right;padding:.4em 0;">' . esc_html((string) $total) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	// Les compteurs sont désormais toujours présents (normalisés) : l'absence
	// de données se lit sur les totaux, pas sur la forme du tableau.
	$recorded = 0;
	foreach ($lines as $key => $label) {
		$recorded += $stats[$key]['total'] ?? 0;
	}

	if (0 === $recorded) {
		echo '<p style="margin:.8em 0 0;color:#646970;">'
			. esc_html__('Aucun clic enregistré pour le moment.', 'g2rd-suivi-contact')
			. '</p>';
	}
}


/**
 * ---------------------------------------------------------------------------
 * 4. Mises à jour automatiques depuis GitHub
 * ---------------------------------------------------------------------------
 *
 * Le plugin n'étant pas publié sur wordpress.org, WordPress n'a par défaut
 * aucune source de mise à jour : les sites resteraient indéfiniment sur la
 * version installée. On branche donc le mécanisme officiel prévu pour ça.
 *
 * L'en-tête « Update URI » du plugin pointe vers github.com ; WordPress 5.8+
 * déclenche alors le filtre update_plugins_github.com lors de chaque contrôle
 * de mises à jour (deux fois par jour via wp_version_check, et à l'ouverture
 * des écrans d'administration concernés). C'est plus sûr que de filtrer
 * directement le transient update_plugins : le cœur garantit qu'un plugin dont
 * l'« Update URI » n'est pas vide ne peut pas être écrasé par une extension
 * homonyme du répertoire officiel.
 *
 * Résultat côté site : la pastille de mise à jour apparaît dans Extensions et
 * dans Tableau de bord → Mises à jour, avec installation en un clic.
 */

/** Clé du cache de la dernière release GitHub. */
const G2RD_CONTACT_RELEASE_CACHE = 'g2rd_contact_latest_release';

/** Clé du backoff appliqué après un appel API en échec. */
const G2RD_CONTACT_RELEASE_BACKOFF = 'g2rd_contact_release_backoff';

/**
 * Normalise une charge utile de release en structure typée.
 *
 * La donnée vient soit de l'API GitHub, soit du cache : dans les deux cas elle
 * est traitée comme non fiable. Toute release qui ne produit pas une version
 * exploitable ET un paquet téléchargeable est rejetée — mieux vaut ne proposer
 * aucune mise à jour qu'en proposer une qui casserait l'installation.
 *
 * @param mixed $raw Charge utile brute.
 * @return array{version: string, package: string, url: string, published: string, changelog: string}|null
 */
function g2rd_contact_release_shape($raw): ?array
{
	if (! is_array($raw)) {
		return null;
	}

	$tag     = $raw['tag_name'] ?? '';
	$version = is_string($tag) ? ltrim($tag, 'vV') : '';

	// Un tag non versionné (« latest », « nightly ») ne doit jamais déclencher
	// de mise à jour : version_compare() en tirerait un résultat arbitraire.
	if (! preg_match('/^\d+\.\d+/', $version)) {
		return null;
	}

	$package = g2rd_contact_release_package($raw);
	if ('' === $package) {
		return null;
	}

	$published = $raw['published_at'] ?? '';
	$body      = $raw['body'] ?? '';
	$html_url  = $raw['html_url'] ?? '';

	return [
		'version'   => $version,
		'package'   => $package,
		'url'       => is_string($html_url) && '' !== $html_url ? $html_url : G2RD_CONTACT_REPO_URL,
		'published' => is_string($published) ? $published : '',
		'changelog' => is_string($body) ? $body : '',
	];
}

/**
 * Extrait l'URL du ZIP de distribution attaché à la release.
 *
 * Seul un asset .zip est accepté. Le zipball généré automatiquement par GitHub
 * est volontairement ignoré : son dossier racine s'appelle {owner}-{repo}-{sha},
 * ce qui installerait l'extension dans un répertoire au mauvais nom et la
 * désactiverait. Le ZIP produit par le workflow de release, lui, contient un
 * dossier « g2rd-suivi-contact/ » correct.
 *
 * @param array<mixed> $raw Charge utile de release.
 * @return string URL de téléchargement, ou chaîne vide si aucun asset valable.
 */
function g2rd_contact_release_package(array $raw): string
{
	$assets = $raw['assets'] ?? [];

	if (! is_array($assets)) {
		return '';
	}

	foreach ($assets as $asset) {
		if (! is_array($asset)) {
			continue;
		}

		$name = $asset['name'] ?? '';
		$url  = $asset['browser_download_url'] ?? '';

		if (is_string($name) && is_string($url) && '' !== $url && str_ends_with($name, '.zip')) {
			return $url;
		}
	}

	return '';
}

/**
 * Récupère la dernière release publiée, avec cache et backoff.
 *
 * L'API GitHub non authentifiée est plafonnée à 60 requêtes/heure/IP. Le
 * contrôle de mises à jour étant déclenché fréquemment, un appel direct
 * épuiserait le quota et ferait disparaître la mise à jour par intermittence.
 * D'où le cache de 6 h, et un backoff court en cas d'échec qui évite de
 * marteler l'API sans jamais écraser une release déjà connue.
 *
 * @return array{version: string, package: string, url: string, published: string, changelog: string}|null
 */
function g2rd_contact_latest_release(): ?array
{
	$cached = get_transient(G2RD_CONTACT_RELEASE_CACHE);
	if (false !== $cached) {
		return g2rd_contact_release_shape($cached);
	}

	// Un appel récent a échoué : ne pas re-solliciter l'API tout de suite.
	if (false !== get_transient(G2RD_CONTACT_RELEASE_BACKOFF)) {
		return null;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . G2RD_CONTACT_REPO . '/releases/latest',
		[
			'timeout' => 10,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WordPress/G2RD-Suivi-Contact',
			],
		]
	);

	// Un 403 « rate limit exceeded » n'est PAS un WP_Error : il faut tester
	// explicitement le code de réponse en plus de is_wp_error().
	if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
		set_transient(G2RD_CONTACT_RELEASE_BACKOFF, 1, 15 * MINUTE_IN_SECONDS);
		return null;
	}

	$payload = json_decode(wp_remote_retrieve_body($response), true);
	$release = g2rd_contact_release_shape($payload);

	if (null === $release) {
		set_transient(G2RD_CONTACT_RELEASE_BACKOFF, 1, 15 * MINUTE_IN_SECONDS);
		return null;
	}

	set_transient(G2RD_CONTACT_RELEASE_CACHE, $payload, 6 * HOUR_IN_SECONDS);
	delete_transient(G2RD_CONTACT_RELEASE_BACKOFF);

	return $release;
}

/**
 * Signale à WordPress qu'une version plus récente est disponible.
 *
 * @param mixed                $update      Réponse construite par un filtre précédent.
 * @param array<string, mixed> $plugin_data En-têtes du plugin examiné.
 * @param string               $plugin_file Chemin du plugin, relatif au dossier plugins.
 * @return mixed Tableau décrivant la mise à jour, ou $update inchangé.
 */
function g2rd_contact_check_update($update, array $plugin_data, string $plugin_file)
{
	// Le filtre est partagé par TOUTES les extensions dont l'« Update URI »
	// pointe vers github.com : on ne répond que pour la nôtre, et on rend la
	// valeur reçue intacte sinon, pour ne pas casser un autre updater.
	if (plugin_basename(__FILE__) !== $plugin_file) {
		return $update;
	}

	$release = g2rd_contact_latest_release();
	if (null === $release) {
		return $update;
	}

	$current = $plugin_data['Version'] ?? '';
	if (! is_string($current) || '' === $current) {
		return $update;
	}

	// Aucune notification si le site est déjà à jour, ou en avance (version de
	// développement installée à la main).
	if (version_compare($current, $release['version'], '>=')) {
		return $update;
	}

	return [
		'id'           => G2RD_CONTACT_REPO_URL,
		'slug'         => G2RD_CONTACT_SLUG,
		'plugin'       => $plugin_file,
		'version'      => $release['version'],
		'url'          => G2RD_CONTACT_REPO_URL,
		'package'      => $release['package'],
		'requires'     => '6.6',
		'requires_php' => '8.0',
	];
}
add_filter('update_plugins_github.com', 'g2rd_contact_check_update', 10, 3);

/**
 * Alimente la fiche « Voir les détails » de l'écran des extensions.
 *
 * Sans ce filtre, le lien de détails renverrait vers le répertoire officiel,
 * qui ne connaît pas ce plugin, et afficherait une erreur.
 *
 * @param mixed  $result Résultat construit par un filtre précédent.
 * @param string $action Action demandée par l'API des extensions.
 * @param mixed  $args   Arguments de la requête.
 * @return mixed Objet d'information, ou $result inchangé.
 */
function g2rd_contact_plugin_info($result, string $action, $args)
{
	if ('plugin_information' !== $action) {
		return $result;
	}

	if (! is_object($args) || ! isset($args->slug) || G2RD_CONTACT_SLUG !== $args->slug) {
		return $result;
	}

	$release = g2rd_contact_latest_release();
	if (null === $release) {
		return $result;
	}

	$info                = new stdClass();
	$info->name          = 'G2RD — Suivi des clics de contact';
	$info->slug          = G2RD_CONTACT_SLUG;
	$info->version       = $release['version'];
	$info->author        = '<a href="https://g2rd.fr">Sebastien GERARD</a>';
	$info->homepage      = G2RD_CONTACT_REPO_URL;
	$info->requires      = '6.6';
	$info->requires_php  = '8.0';
	$info->last_updated  = $release['published'];
	$info->download_link = $release['package'];
	$info->sections      = [
		'description' => '<p>' . esc_html__(
			'Compte les clics sur les liens téléphone et e-mail, envoie un événement vers Google Analytics et tient un compteur local affiché dans le tableau de bord.',
			'g2rd-suivi-contact'
		) . '</p>',
		// Le corps de la release est du Markdown rédigé sur GitHub : il est
		// échappé intégralement avant mise en forme, jamais interprété comme
		// du HTML. Une release piégée ne peut donc pas injecter de script
		// dans l'administration du site.
		'changelog'   => wpautop(esc_html($release['changelog'])),
	];

	return $info;
}
add_filter('plugins_api', 'g2rd_contact_plugin_info', 10, 3);

/**
 * Vide le cache de release quand l'administrateur force un contrôle.
 *
 * Sans ça, le bouton « Vérifier à nouveau » de l'écran des mises à jour
 * resservirait la réponse mise en cache, et une release tout juste publiée
 * n'apparaîtrait qu'au bout de 6 h.
 */
function g2rd_contact_flush_release_cache(): void
{
	// Lecture d'un simple drapeau de présence dans l'URL, sans traitement de sa
	// valeur : WordPress lui-même ne pose pas de nonce sur « force-check ».
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if (! isset($_GET['force-check'])) {
		return;
	}

	delete_transient(G2RD_CONTACT_RELEASE_CACHE);
	delete_transient(G2RD_CONTACT_RELEASE_BACKOFF);
}
add_action('load-update-core.php', 'g2rd_contact_flush_release_cache');
