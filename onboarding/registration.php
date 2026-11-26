<?php
declare(strict_types=1);

const ONBOARDING_REGISTRATION_FLAG = '/etc/mooncrater/onboarding-registration.enabled';
const ONBOARDING_REGISTRATION_PROFILE = '/etc/mooncrater/onboarding-registration.yml';
const ONBOARDING_REGISTRATION_MAX_HOSTS = 20;
const ONBOARDING_SSH_PUBLIC_KEY = '/etc/mooncrater/onboarding-credentials.pub.pem';
const ONBOARDING_SSH_PRIVATE_KEY = '/etc/mooncrater/onboarding-credentials.key.pem';

function onboardingSealSshCredentials(mixed $username, mixed $password): ?string
{
    if (!is_string($username) || !preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.-]{0,63}\z/', $username)
        || !is_string($password) || $password === '' || strlen($password) > 128
        || !preg_match('//u', $password) || preg_match('/[\x00-\x1f\x7f]/', $password)
        || !function_exists('openssl_public_encrypt') || !is_readable(ONBOARDING_SSH_PUBLIC_KEY)) {
        return null;
    }
    $key = openssl_pkey_get_public('file://' . ONBOARDING_SSH_PUBLIC_KEY);
    // Encode the password before JSON serialization to keep the encrypted payload bounded.
    $payload = json_encode([$username, base64_encode($password)], JSON_THROW_ON_ERROR);
    if ($key === false || !openssl_public_encrypt($payload, $encrypted, $key, OPENSSL_PKCS1_OAEP_PADDING)) {
        return null;
    }
    return base64_encode($encrypted);
}

function onboardingOpenSshCredentials(string $sealed): array
{
    if ($sealed === '' || !function_exists('openssl_private_decrypt') || !is_readable(ONBOARDING_SSH_PRIVATE_KEY)) {
        throw new RuntimeException('SSH credentials unavailable.');
    }
    $ciphertext = base64_decode($sealed, true);
    $key = openssl_pkey_get_private('file://' . ONBOARDING_SSH_PRIVATE_KEY);
    if ($key === false || $ciphertext === false
        || !openssl_private_decrypt($ciphertext, $payload, $key, OPENSSL_PKCS1_OAEP_PADDING)) {
        throw new RuntimeException('SSH credentials unavailable.');
    }
    [$username, $password] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    return ['ansible_user' => $username, 'ansible_password' => base64_decode($password, true)];
}

function onboardingRegistrationEnabled(): bool
{
    return is_file(ONBOARDING_REGISTRATION_FLAG);
}

function onboardingSyncRegisteredHost(PDO $pdo, array $record): void
{
    $optional = static fn ($value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
    $checkin = $optional($record['last_checkin']);
    $statement = $pdo->prepare("
        INSERT INTO inventory_satellite (hostname, os, ip, content_view_environment, location, last_checkin, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (hostname) DO UPDATE SET
            os = COALESCE(EXCLUDED.os, inventory_satellite.os),
            ip = COALESCE(EXCLUDED.ip, inventory_satellite.ip),
            content_view_environment = COALESCE(EXCLUDED.content_view_environment, inventory_satellite.content_view_environment),
            location = COALESCE(EXCLUDED.location, inventory_satellite.location),
            last_checkin = COALESCE(EXCLUDED.last_checkin, inventory_satellite.last_checkin),
            status = CASE
                WHEN inventory_satellite.status = 'excluded' THEN 'excluded'
                WHEN EXCLUDED.last_checkin IS NULL AND inventory_satellite.status IN ('active', 'unhealthy') THEN inventory_satellite.status
                ELSE EXCLUDED.status
            END
        WHERE COALESCE(inventory_satellite.role, '') NOT IN ('satellite', 'capsule')
        RETURNING hostname
    ");
    $statement->execute([
        $record['name'], $optional($record['os']), $optional($record['ip']),
        $optional($record['content_view_environment']), $optional($record['location']),
        $checkin, satellite_status_from_checkin($checkin),
    ]);
    if ($statement->fetchColumn() === false) throw new RuntimeException('Inventory row is protected.');
}
