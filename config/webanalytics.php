<?php

return [

    // Clé publique du site (wa_pub_…) — panneau « Installation » de l'espace membre.
    'public_key' => env('WEBANALYTICS_PUBLIC_KEY'),

    // Clé secrète (wa_sec_…) — active le mode signé : l'IP/UA du visiteur
    // transmis par le serveur font foi (docs/05-api-et-sdk.md §1).
    'secret_key' => env('WEBANALYTICS_SECRET_KEY'),

    'endpoint' => env('WEBANALYTICS_ENDPOINT', 'https://collect.example.fr/api/v1/collect'),

    // À activer si l'app est derrière un reverse proxy / CDN (X-Forwarded-For).
    'trust_proxy_headers' => (bool) env('WEBANALYTICS_TRUST_PROXY', false),

];
