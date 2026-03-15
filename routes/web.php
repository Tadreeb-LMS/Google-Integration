<?php

Route::middleware(['auth'])->prefix('external-apps/google-meet')->group(function () {
    Route::post('/test-connection', 'Controllers\GoogleMeetController@testConnection');
});
