<?php
/**
 * /api/visitor_move.php — Accept a visitor move (CAPTCHA-gated)
 *
 * Intent:
 * - Accept exactly ONE visitor move when it is visitors' turn.
 * - Guard submission with CAPTCHA to reduce abuse.
 * - Update game state, flip turn to host, and notify host by email.
 *
 * Required checks (server-side):
 * - CAPTCHA verification MUST pass (Turnstile/hCaptcha/reCAPTCHA).
 * - Game MUST be active.
 * - It MUST be the visitors' turn.
 * - Concurrency control: only one submission can win for a given turn (lock).
 *
 * Data accepted (MVP):
 * - Updated FEN and PGN representing the move made client-side
 * - Move notation (SAN or coordinate)
 * - CAPTCHA token
 *
 * Security note (important):
 * - Client-side chess.js checks are not authoritative; malicious clients can bypass them.
 * - MVP enforces turn/locking/CAPTCHA; later hardening adds server-side move validation.
 *
 * Side effects:
 * - Save updated state in games table
 * - Create a single-use token for host to make the next move
 * - Email host a link to /my_move.php?token=...
 */

