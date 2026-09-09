<?php
declare(strict_types=1);

// Preserve old bookmarks while keeping one hardened registration flow.
header('Location: /register.php', true, 302);
exit;
