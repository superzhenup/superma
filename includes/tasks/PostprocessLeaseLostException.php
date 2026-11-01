<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * Signals that a post-processing worker no longer owns the durable lease.
 *
 * Kept outside WriteEngine so repositories, runners and the engine can share
 * the same fail-fast signal without depending on include order.
 */
class WriteEnginePostprocessLeaseLostException extends RuntimeException {}
