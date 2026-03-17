<?php

Route::middleware(['auth'])->prefix('external-apps/google-meet-integration')->group(function () {
    Route::post('/test-connection', 'Controllers\GoogleMeetController@testConnection');
});
