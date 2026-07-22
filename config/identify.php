<?php

declare(strict_types=1);

return [
    // Max tool-loop steps (LLM turns that may invoke tools) per identify agent turn.
    'max_tool_steps' => (int) env('IDENTIFY_MAX_TOOL_STEPS', 8),
    // Wall-clock seconds for a single agent turn (job + AI client timeout).
    'turn_timeout_seconds' => (int) env('IDENTIFY_TURN_TIMEOUT_SECONDS', 90),
];
