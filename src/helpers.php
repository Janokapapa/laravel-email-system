<?php

namespace JanDev\EmailSystem;

/**
 * Resolve a callback from config - supports closures, invokable class names, and [class, method] arrays.
 */
function resolve_callback(mixed $callback): ?callable
{
    if ($callback === null) {
        return null;
    }

    if ($callback instanceof \Closure) {
        return $callback;
    }

    if (is_string($callback) && class_exists($callback)) {
        return app($callback);
    }

    if (is_array($callback) && count($callback) === 2) {
        return $callback;
    }

    if (is_callable($callback)) {
        return $callback;
    }

    return null;
}
