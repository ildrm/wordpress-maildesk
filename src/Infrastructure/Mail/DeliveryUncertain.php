<?php
namespace WPMailDesk\Infrastructure\Mail;

/** SMTP may have accepted DATA; automatically retrying could duplicate delivery. */
final class DeliveryUncertain extends \RuntimeException {}
