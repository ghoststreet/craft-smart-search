<?php

namespace ghoststreet\craftsmartsearch\exceptions;

class ConfigurationException extends SmartSearchException
{
    public static function missingApiKey(string $service): self
    {
        return self::build("{$service} API key is not configured.", ErrorCode::CONFIG_MISSING_API_KEY);
    }
}
