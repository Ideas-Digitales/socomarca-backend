<?php

use Symfony\Component\Yaml\Yaml;

test('mailpit is limited to the qa compose profile', function () {
    $compose = Yaml::parseFile(base_path('compose.deploy.yml'));
    $mailpit = $compose['services']['mailpit'];

    expect($mailpit['profiles'])->toBe(['qa'])
        ->and($mailpit['image'])->toBe('axllent/mailpit:v1.31.6')
        ->and($mailpit['container_name'])->toBe('mailpit')
        ->and($mailpit['restart'])->toBe('unless-stopped')
        ->and($mailpit['ports'])->toBe(['127.0.0.1:1025:1025'])
        ->and($mailpit['networks'])->toBe(['traefik-public', 'internal'])
        ->and($mailpit['environment'])->toMatchArray([
            'MP_UI_AUTH' => '${MAILPIT_UI_AUTH:-}',
            'MP_ALLOWED_HOSTS' => '${MAILPIT_HOST:-}',
            'MP_DATABASE' => '/data/mailpit.db',
            'MP_MAX_MESSAGES' => '5000',
            'MP_SMTP_AUTH_ACCEPT_ANY' => '1',
            'MP_SMTP_AUTH_ALLOW_INSECURE' => '1',
        ])
        ->and($mailpit['labels'])->toContain(
            'traefik.enable=true',
            'traefik.docker.network=traefik-public',
            'traefik.http.routers.mailpit.rule=Host(`${MAILPIT_HOST:-}`)',
            'traefik.http.routers.mailpit.entrypoints=websecure',
            'traefik.http.routers.mailpit.tls.certresolver=myresolver',
            'traefik.http.services.mailpit.loadbalancer.server.port=8025',
        )
        ->and($mailpit['volumes'])->toBe(['mailpit_data:/data'])
        ->and($compose['volumes']['mailpit_data']['name'])->toBe('mailpit_data_qa')
        ->and(collect($mailpit['ports'])->contains(fn ($port) => str_contains((string) $port, '8025')))->toBeFalse();
});

test('production deploy does not enable the mailpit profile', function () {
    $workflow = file_get_contents(base_path('.github/workflows/deploy.yml'));

    expect($workflow)->toContain("if: env.IMAGE_TAG == 'qa'")
        ->and($workflow)->toContain('if [ "${{ env.IMAGE_TAG }}" = "qa" ]; then')
        ->and($workflow)->toContain('export COMPOSE_PROFILES=qa')
        ->and($workflow)->toContain('docker compose up mailpit -d')
        ->and(substr_count($workflow, 'docker compose up mailpit'))->toBe(1);

    $deploy = substr($workflow, (int) strpos($workflow, 'Clean and deploy containers'));
    $prodSteps = preg_replace(
        '/if \[ ["\']\$\{\{ env\.IMAGE_TAG \}\}["\'] = ["\']qa["\'] \]; then.*?fi/s',
        '',
        $deploy,
    );

    expect($prodSteps)->not->toContain('COMPOSE_PROFILES')
        ->and($prodSteps)->not->toContain('mailpit');
});

test('qa mailpit settings are documented in the env example', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('MAILPIT_HOST=')
        ->and($example)->toContain('MAILPIT_UI_AUTH=');
});
