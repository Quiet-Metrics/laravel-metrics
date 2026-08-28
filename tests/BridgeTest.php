<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Tests;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use QuietMetrics\Client;
use QuietMetrics\Laravel\Facades\QuietMetrics;
use QuietMetrics\Laravel\QuietMetricsServiceProvider;
use QuietMetrics\Tests\CaptureServer;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Le pont testé comme un vrai projet Laravel (Testbench) : provider, config,
 * middleware `quiet-metrics` en terminate(), facade, le tout contre un serveur
 * de capture HTTP réel (celui du package cœur, via le symlink du path repo).
 */
final class BridgeTest extends TestCase
{
    private static CaptureServer $server;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/../vendor/quiet-metrics/php-metrics/tests/CaptureServer.php';
        self::$server = new CaptureServer();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$server->reset();
    }

    protected function getPackageProviders($app): array
    {
        return [QuietMetricsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('quiet-metrics.public_key', 'qm_pub_test');
        $app['config']->set('quiet-metrics.secret_key', 'qm_sec_test');
        $app['config']->set('quiet-metrics.endpoint', self::$server->endpoint());
        $app['config']->set('quiet-metrics.trust_proxy_headers', false);

        // Le groupe `web`, ou vit desormais le middleware du marqueur, embarque
        // le chiffrement des cookies de Laravel : sans cle, toute requete qui
        // le traverse leve MissingAppKeyException. Rien a voir avec le SDK,
        // c'est le minimum pour eprouver une route `web` sous Testbench.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('qm', 16)));
    }

    public function test_le_middleware_envoie_une_pageview_signee_apres_la_reponse(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $this->get('/tarifs', ['User-Agent' => 'NavigateurTest/1.0'])->assertOk();

        $requests = self::$server->requests();
        $this->assertCount(1, $requests);

        $payload = json_decode($requests[0]['body'], true);
        $this->assertSame('qm_pub_test', $payload['k']);
        $this->assertSame('pageview', $payload['t']);
        $this->assertStringEndsWith('/tarifs', $payload['u']);
        $this->assertSame('NavigateurTest/1.0', $payload['ua']);

        // Signature HMAC valide : le serveur de collecte honorera ip/ua/ts.
        $timestamp = $requests[0]['headers']['x-qm-timestamp'];
        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$requests[0]['body'], 'qm_sec_test'),
            $requests[0]['headers']['x-qm-signature'],
        );
    }

    public function test_le_middleware_ignore_json_erreurs_et_non_get(): void
    {
        Route::middleware('quiet-metrics')->get('/api-like', fn () => response('ok'));
        Route::middleware('quiet-metrics')->post('/form', fn () => response('ok'));
        Route::middleware('quiet-metrics')->get('/introuvable', fn () => response('non', 404));

        $this->getJson('/api-like')->assertOk();          // attend du JSON → ignoré
        $this->post('/form')->assertOk();                 // non-GET → ignoré
        $this->get('/introuvable')->assertNotFound();     // réponse non 2xx → ignorée

        $this->assertSame([], self::$server->requests(1, 400));
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function annoncesDePrechargement(): array
    {
        return [
            'Chrome prerender' => ['Sec-Purpose', 'prefetch;prerender'],
            'Chrome, forme ancienne' => ['Purpose', 'prefetch'],
            'Firefox' => ['X-Moz', 'prefetch'],
        ];
    }

    /**
     * Un préchargement n'est pas une visite, et le middleware doit le lire
     * sur la Request et non dans `$_SERVER` : sous Octane la superglobale peut
     * appartenir à la requête précédente.
     */
    #[DataProvider('annoncesDePrechargement')]
    public function test_le_middleware_ignore_un_prechargement_du_navigateur(string $entete, string $valeur): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $this->get('/tarifs', ['User-Agent' => 'NavigateurTest/1.0', $entete => $valeur])->assertOk();

        $this->assertSame([], self::$server->requests(1, 400), $entete.' annonce un préchargement');
    }

    /**
     * Le refus ne depend pas du middleware de mesure.
     *
     * Le marqueur etait pose par TrackPageview, qui s'applique route par
     * route : une application qui n'envoie que des evenements manuels, ou qui
     * ne trace qu'une partie de ses routes, laissait donc `?qm_ignore=1` sans
     * effet. La LECTURE du refus fonctionnait pourtant, le SDK coeur lisant
     * le cookie, si bien qu'un visiteur restait exclu s'il s'etait retire
     * ailleurs mais ne pouvait plus le faire ici. Un mecanisme de refus ne
     * depend pas d'une option de mesure : le middleware dedie est pousse dans
     * le groupe `web`, comme le bundle Symfony enregistre son listener meme
     * sans pageview automatique.
     */
    public function test_le_marqueur_se_pose_sur_une_route_non_tracee(): void
    {
        Route::middleware('web')->get('/mentions', fn () => response('ok'));

        $reponse = $this->get('/mentions?'.Client::OPT_OUT_MARKER.'=1')->assertOk();

        // `false` : le marqueur est deliberement en clair, sans quoi le traceur
        // JS du meme site ne reconnaitrait pas le refus pose cote serveur.
        $cookie = $reponse->getCookie(Client::OPT_OUT_MARKER, false);
        $this->assertNotNull($cookie, 'le refus doit etre posable meme la ou rien n est mesure');
        $this->assertSame('1', $cookie->getValue());

        $this->assertSame([], self::$server->requests(1, 400), 'et cette route ne mesure toujours rien');
    }

    public function test_la_facade_envoie_un_evenement(): void
    {
        QuietMetrics::event('inscription', ['plan' => 'pro'], ['url' => 'https://monsite.fr/register']);

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertSame('event', $payload['t']);
        $this->assertSame('inscription', $payload['n']);
        $this->assertSame(['plan' => 'pro'], $payload['p']);
    }

    public function test_le_client_est_un_singleton_configure(): void
    {
        $this->assertSame($this->app->make(Client::class), $this->app->make(Client::class));
    }

    public function test_la_config_est_publiee_sous_le_nom_que_le_provider_relit(): void
    {
        $chemins = ServiceProvider::pathsToPublish(
            QuietMetricsServiceProvider::class,
            'quiet-metrics-config',
        );

        $this->assertNotEmpty($chemins, 'le tag de publication quiet-metrics-config a disparu');

        foreach ($chemins as $destination) {
            $this->assertSame(
                'quiet-metrics.php',
                basename($destination),
                'la config publiee doit porter le nom de la cle relue par register() (quiet-metrics), sinon l edition de l utilisateur reste sans effet',
            );
        }
    }

    /**
     * Le marqueur d'exclusion pose par la personne arrete la mesure.
     *
     * Lu sur la Request et jamais dans `$_COOKIE` : sous Octane la
     * superglobale peut appartenir a la requete precedente, et le refus d'un
     * visiteur exclurait alors le suivant.
     */
    public function test_le_middleware_n_envoie_rien_quand_le_marqueur_d_exclusion_est_pose(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $this->call('GET', '/tarifs', [], [Client::OPT_OUT_MARKER => '1'])->assertOk();

        $this->assertSame([], self::$server->requests(1, 400));
    }

    /**
     * `?qm_ignore=1` pose le marqueur sur la REPONSE, et la visite qui le pose
     * ne se compte pas elle-meme.
     *
     * Le cookie se pose pendant la phase reponse et pas dans terminate() :
     * terminate() s'execute apres l'envoi de la reponse au visiteur, il y
     * serait trop tard pour ajouter un en-tete.
     */
    public function test_le_middleware_pose_le_marqueur_demande_par_l_url_sans_compter_la_visite(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $response = $this->get('/tarifs?'.Client::OPT_OUT_MARKER.'=1');
        $response->assertOk();

        $cookie = $response->getCookie(Client::OPT_OUT_MARKER, false);
        $this->assertNotNull($cookie, 'le marqueur doit etre pose sur la reponse');
        $this->assertSame('1', $cookie->getValue());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertFalse($cookie->isHttpOnly(), 'le traceur JS doit lire le meme marqueur');
        $this->assertEqualsWithDelta(
            time() + Client::OPT_OUT_LIFETIME,
            $cookie->getExpiresTime(),
            60,
            'cinq ans, comme le traceur JS',
        );

        $this->assertSame(
            [],
            self::$server->requests(1, 400),
            'la visite qui pose le refus ne se compte pas elle-meme',
        );
    }

    /** `?qm_ignore=0` retire le marqueur, et la visite recompte des maintenant. */
    public function test_le_middleware_retire_le_marqueur_demande_par_l_url_et_recompte_la_visite(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $response = $this->call(
            'GET',
            '/tarifs',
            [Client::OPT_OUT_MARKER => '0'],
            [Client::OPT_OUT_MARKER => '1'],
        );
        $response->assertOk();
        $response->assertCookieExpired(Client::OPT_OUT_MARKER);

        $this->assertCount(
            1,
            self::$server->requests(),
            'retirer le refus remet la personne dans la mesure des cette visite',
        );
    }

    /** Une URL sans signal ne pose ni ne retire quoi que ce soit. */
    public function test_le_middleware_ne_touche_au_marqueur_que_sur_signal(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $this->get('/tarifs')->assertCookieMissing(Client::OPT_OUT_MARKER);
    }

    /**
     * Le marqueur echappe au chiffrement des cookies de Laravel.
     *
     * Chiffre, il ne vaudrait plus `1` chez le visiteur : le traceur JS du
     * meme site ne reconnaitrait plus le refus, et le mode « les deux »
     * continuerait de mesurer une personne qui s'en est exclue.
     */
    public function test_le_marqueur_d_exclusion_n_est_jamais_chiffre(): void
    {
        // Chiffreur construit a la main : la question porte sur la liste des
        // cookies exemptes, pas sur la cle applicative de l'hote.
        $middleware = new EncryptCookies(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'));

        $this->assertTrue($middleware->isDisabled(Client::OPT_OUT_MARKER));
    }

    /**
     * Continuite de visite : le cookie se pose pendant la phase REPONSE.
     *
     * terminate() s'execute apres l'envoi de la reponse au visiteur, il y
     * serait trop tard pour un Set-Cookie, exactement comme pour le marqueur
     * d'exclusion. Le premier hit ne porte pas `c` : rien n'etait ouvert au
     * moment ou il est parti.
     */
    public function test_le_middleware_ouvre_la_fenetre_de_visite_sur_un_hit_mesure(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $response = $this->get('/tarifs', ['User-Agent' => 'NavigateurTest/1.0']);
        $response->assertOk();

        // `false` : le cookie est deliberement en clair, sans quoi le traceur
        // JS du meme site ouvrirait une seconde fenetre pour la meme visite.
        $cookie = $response->getCookie(Client::VISIT_MARKER, false);
        $this->assertNotNull($cookie);
        $this->assertSame('1', $cookie->getValue(), 'valeur constante : elle n identifie personne');
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertFalse($cookie->isHttpOnly(), 'le traceur JS doit lire la meme fenetre');
        $this->assertEqualsWithDelta(
            time() + Client::VISIT_LIFETIME,
            $cookie->getExpiresTime(),
            60,
            'dix minutes, comme le traceur JS',
        );

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertArrayNotHasKey('c', $payload, 'premier hit : aucune visite n etait en cours');
    }

    /**
     * Le hit suivant porte `c`, et la fenetre glisse.
     *
     * L'etat est lu sur la Request (ce que le navigateur a envoye) et jamais
     * dans `$_COOKIE` : sous Octane la superglobale peut appartenir a la
     * requete precedente, et deux visiteurs seraient recolles en un.
     */
    public function test_le_hit_suivant_porte_c_et_repousse_la_fenetre(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $response = $this->call('GET', '/tarifs', [], [Client::VISIT_MARKER => '1']);
        $response->assertOk();

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertSame(1, $payload['c'], 'une visite etait deja en cours sur ce navigateur');

        $cookie = $response->getCookie(Client::VISIT_MARKER, false);
        $this->assertNotNull($cookie, 'expiration glissante : chaque hit repousse la fenetre');
        $this->assertEqualsWithDelta(time() + Client::VISIT_LIFETIME, $cookie->getExpiresTime(), 60);
    }

    /** Rien de mesure, rien d'ecrit : la fenetre suit le hit, pas la requete. */
    public function test_aucune_fenetre_de_visite_quand_rien_n_est_mesure(): void
    {
        Route::middleware('quiet-metrics')->get('/api-like', fn () => response('ok'));
        Route::middleware('quiet-metrics')->post('/form', fn () => response('ok'));
        Route::middleware('quiet-metrics')->get('/introuvable', fn () => response('non', 404));

        $this->getJson('/api-like')->assertCookieMissing(Client::VISIT_MARKER);
        $this->post('/form')->assertCookieMissing(Client::VISIT_MARKER);
        $this->get('/introuvable')->assertCookieMissing(Client::VISIT_MARKER);
    }

    /** Un prechargement n'est pas une visite : il n'en ouvre donc pas la fenetre. */
    public function test_un_prechargement_n_ouvre_pas_de_fenetre_de_visite(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $this->get('/tarifs', ['Sec-Purpose' => 'prefetch;prerender'])
            ->assertCookieMissing(Client::VISIT_MARKER);
    }

    /**
     * On n'ecrit RIEN chez quelqu'un qui a refuse la mesure.
     *
     * Ni sur la requete de celui qui a deja pose son refus, ni sur celle qui
     * le pose : le refus vaut des la requete qui le demande.
     */
    public function test_aucune_fenetre_de_visite_chez_une_personne_exclue(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $this->call('GET', '/tarifs', [], [Client::OPT_OUT_MARKER => '1'])
            ->assertCookieMissing(Client::VISIT_MARKER);

        $this->get('/tarifs?'.Client::OPT_OUT_MARKER.'=1')
            ->assertCookieMissing(Client::VISIT_MARKER);
    }

    /**
     * La fenetre de visite echappe au chiffrement des cookies de Laravel.
     *
     * Chiffree, sa valeur ne vaudrait plus `1` chez le visiteur : le traceur
     * JS du meme site ne la reconnaitrait pas, ouvrirait la sienne, et le mode
     * « les deux » compterait de nouveau deux visiteurs pour une personne.
     * Elle ne contient rien a proteger, c'est la meme valeur chez tout le
     * monde.
     */
    public function test_le_cookie_de_visite_n_est_jamais_chiffre(): void
    {
        $middleware = new EncryptCookies(new Encrypter(str_repeat('k', 32), 'aes-256-cbc'));

        $this->assertTrue($middleware->isDisabled(Client::VISIT_MARKER));
    }
}
