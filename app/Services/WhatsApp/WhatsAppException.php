<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** Meta refused a message; the text is ready to show (Arabic when the code is known). */
class WhatsAppException extends RuntimeException
{
}
