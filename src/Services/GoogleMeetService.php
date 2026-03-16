<?php
namespace Modules\GoogleMeetIntegration\Services;
use App\Services\ExternalApps\ExternalAppService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class GoogleMeetService
{
    private $projectId;
    private $clientId;
    private $clientSecret;
    private $serviceAccountJson;
    private $adminEmail;
    public function __construct()
    {
        $app = \App\Models\ExternalApp::where('slug', 'google-meet-integration')->first();
        $this->projectId = $app->configuration['GOOGLE_PROJECT_ID'] ?? '';
        $this->clientId = $app->configuration['GOOGLE_CLIENT_ID'] ?? '';
        $this->clientSecret = $app->configuration['GOOGLE_CLIENT_SECRET'] ?? '';
        $this->serviceAccountJson = $app->configuration['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? '';
        // Fallback to .env if empty
        if (empty($this->clientId)) {
             $this->clientId = ExternalAppService::staticGetModuleEnv('google-meet-integration', 'GOOGLE_CLIENT_ID');
        }
        if (empty($this->clientSecret)) {
             $this->clientSecret = ExternalAppService::staticGetModuleEnv('google-meet-integration', 'GOOGLE_CLIENT_SECRET');
        }
        if (empty($this->projectId)) {
             $this->projectId = ExternalAppService::staticGetModuleEnv('google-meet-integration', 'GOOGLE_PROJECT_ID');
        }
        if (empty($this->serviceAccountJson)) {
             $this->serviceAccountJson = ExternalAppService::staticGetModuleEnv('google-meet-integration', 'GOOGLE_SERVICE_ACCOUNT_JSON');
        }
        $this->adminEmail = $app->configuration['GOOGLE_ADMIN_EMAIL'] ?? '';
        if (empty($this->adminEmail)) {
            $this->adminEmail = ExternalAppService::staticGetModuleEnv('google-meet-integration', 'GOOGLE_ADMIN_EMAIL');
        }
    }
    /**
     * Test the connection to Google APIs.
     * Since we don't have an auth code yet, we can simply ping the token endpoint
     * to verify the client_id format and reachability.
     */
    public function testConnection($projectId, $clientId, $clientSecret, $serviceAccountJson = null)
    {
        try {
            // If they provided a Service Account JSON, let's check if it's valid JSON
            if (!empty($serviceAccountJson)) {
                $decoded = json_decode($serviceAccountJson, true);
                if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['project_id'])) {
                    Log::error('Google Meet: Invalid Service Account JSON.');
                    return false;
                }
            }
            // We ping Google's OAuth 2.0 endpoint. 
            // It will return an error (unsupported_grant_type or invalid_client), 
            // but receiving that exact JSON from Google proves connectivity.
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'grant_type'    => 'ping_test_connection'
            ]);
            $body = $response->json();
            
            // If Google replies with "invalid_client", the credentials might be wrong, 
            // but if it replies with something else standard like unsupported_grant_type, 
            // the client ID is at least formatted correctly and Google responded.
            if (isset($body['error'])) {
                if ($body['error'] === 'invalid_client') {
                    Log::error('Google Meet Test Connection: Invalid Client ID or Secret.');
                    return false;
                }
                return true; 
            }
            return false;
        } catch (\Exception $e) {
            Log::error('Google Meet Test Connection Exception: ' . $e->getMessage());
            return false;
        }
    }
    /**
     * Create a meeting using Google Calendar API (Events.insert)
     * with conferenceDataVersion=1.
     */
    public function createMeeting($topic, $startTime, $duration, $timezone, $hostEmail = null, $attendees = [])
    {
        Log::info("Google Meet createMeeting called for {$topic}");
        if (empty($this->serviceAccountJson)) {
            Log::error("Google Meet: Service Account JSON is missing from configuration.");
            return null;
        }
        $decodedSa = json_decode($this->serviceAccountJson, true);
        if (!$decodedSa || !isset($decodedSa['private_key'])) {
            Log::error("Google Meet: Invalid Service Account JSON.");
            return null;
        }
        $impersonateEmail = $hostEmail ?? $this->adminEmail;
        $token = $this->getAccessToken($decodedSa, $impersonateEmail);
        
        if (!$token && $hostEmail && $hostEmail !== $this->adminEmail) {
            Log::warning("Google Meet: Failed to impersonate teacher '{$hostEmail}'. Falling back to admin '{$this->adminEmail}'. Personal @gmail.com accounts cannot be impersonated.");
            $impersonateEmail = $this->adminEmail;
            $token = $this->getAccessToken($decodedSa, $impersonateEmail);
        }

        if (!$token) {
            Log::error("Google Meet: Failed to obtain access token for email: {$impersonateEmail}");
            return null;
        }

        if ($impersonateEmail && str_contains($impersonateEmail, '.gserviceaccount.com')) {
            Log::warning("Google Meet: Impersonation Email '{$impersonateEmail}' appears to be a Service Account email. Impersonation (Domain-Wide Delegation) requires a real Workspace user email.");
        }
        $startCarbon = \Carbon\Carbon::parse($startTime, $timezone);
        $endCarbon = $startCarbon->copy()->addMinutes((int) $duration);
        $eventData = [
            'summary' => $topic,
            'start' => [
                'dateTime' => $startCarbon->format('Y-m-d\TH:i:s'),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $endCarbon->format('Y-m-d\TH:i:s'),
                'timeZone' => $timezone,
            ],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => uniqid('meet_req_'),
                    'conferenceSolutionKey' => [
                        'type' => 'hangoutsMeet'
                    ]
                ]
            ],
            'attendees' => array_map(function($email) {
                return ['email' => $email];
            }, $attendees)
        ];
        Log::info("Google Meet Request Payload: " . json_encode($eventData));
        try {
            $response = Http::withToken($token)
                ->post('https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1', $eventData);
            
            $event = $response->json();
            if (!$response->successful() || isset($event['error'])) {
                Log::error("Google Meet API Error Response: " . $response->body());
                return null;
            }
            $meetLink = $event['hangoutLink'] ?? null;
            $eventId  = $event['id'] ?? uniqid('meet_');
            if ($meetLink) {
                Log::info("Google Meet: Created meeting successfully. Link: {$meetLink}");
                return [
                    'id'       => $eventId,
                    'join_url' => $meetLink,
                    'host_url' => $meetLink,
                ];
            } else {
                Log::error("Google Meet: No hangoutLink returned. Full response: " . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Google Meet API Exception: ' . $e->getMessage());
            return null;
        }
    }
    /**
     * Generate an OAuth 2.0 access token using a Google Service Account JSON.
     * Supports Domain-Wide Delegation via the 'sub' claim.
     */
    private function getAccessToken($decodedSa, $subEmail = null)
    {
        try {
            $clientEmail = $decodedSa['client_email'];
            $privateKey  = $decodedSa['private_key'];
            $tokenUri    = $decodedSa['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            $header = json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT'
            ]);

            $now = time();
            $claimData = [
                'iss'   => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/calendar.events',
                'aud'   => $tokenUri,
                'exp'   => $now + 3600,
                'iat'   => $now
            ];

            if ($subEmail) {
                $claimData['sub'] = $subEmail;
            }

            $claimSet = json_encode($claimData);
            $encodedHeader   = $this->base64UrlEncode($header);
            $encodedClaimSet = $this->base64UrlEncode($claimSet);
            $signatureInput = $encodedHeader . '.' . $encodedClaimSet;
            
            $signature = '';
            if (!openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                Log::error("Google Meet: Failed to sign JWT with OpenSSL.");
                return null;
            }
            $encodedSignature = $this->base64UrlEncode($signature);
            $jwt = $signatureInput . '.' . $encodedSignature;
            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt
            ]);
            $body = $response->json();
            if (isset($body['access_token'])) {
                return $body['access_token'];
            }
            Log::error("Google Meet: Failed to exchange JWT for Access Token for {$subEmail}: " . json_encode($body));
            return null;
        } catch (\Exception $e) {
            Log::error('Google Meet JWT Token Exception: ' . $e->getMessage());
            return null;
        }
    }
    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}