<?php
/** Compatibility contract for WordPress's PHPMailer subset, which omits OAuth helpers. */
namespace PHPMailer\PHPMailer;

interface OAuthTokenProvider {
    public function getOauth64();
}
