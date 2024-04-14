<?php

namespace Stichoza\GoogleTranslate;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Stichoza\GoogleTranslate\Exceptions\LargeTextException;
use Stichoza\GoogleTranslate\Exceptions\RateLimitException;
use Stichoza\GoogleTranslate\Exceptions\TranslationDecodingException;
use Stichoza\GoogleTranslate\Exceptions\TranslationRequestException;
use Stichoza\GoogleTranslate\Tokens\GoogleTokenGenerator;
use Stichoza\GoogleTranslate\Tokens\TokenProviderInterface;
use Throwable;

/**
 * Free Google Translate API PHP Package.
 *
 * @author      Levan Velijanashvili <me@stichoza.com>
 * @link        https://stichoza.com/
 * @license     MIT
 */
class GoogleBatchTranslate
{
    /**
     * @var \GuzzleHttp\Client HTTP Client
     */
    protected Client $client;

    /**
     * @var string|null Source language which the string should be translated from.
     */
    protected ?string $source;

    /**
     * @var string|null Target language which the string should be translated to.
     */
    protected ?string $target;

    /*
     * @var string|null Regex pattern to match replaceable parts in a string, defualts to "words"
     */
    protected ?string $pattern;

    protected ?string $token = 'AIzaSyATBXajvzQLTDHEQbcpq0Ihe0vWDHmO520';

    /**
     * @var string|null Last detected source language.
     */
    protected ?string $lastDetectedSource;

    /**
     * @var string Google Translate base URL.
     */
    protected string $url = 'https://translate-pa.googleapis.com/v1/translateHtml';

    protected ?string $proxy;

    /**
     * @var array Dynamic GuzzleHttp client options
     */
    protected array $options = [];

    /**
     * @var array URL Parameters
     */
    protected array $dataArray = [
        [
            ["1"],
            "auto",
            "zh-CN"
        ],
        "wt_lib"
    ];

    /**
     * @var array Regex key-value patterns to replace on response data
     */
    protected array $resultRegexes = [
        '/,+/'  => ',',
        '/\[,/' => '[',
        '/\xc2\xa0/' => ' ',
    ];

    /**
     * @var TokenProviderInterface Token provider
     */
    protected TokenProviderInterface $tokenProvider;

    /**
     * Class constructor.
     *
     * For more information about HTTP client configuration options, see "Request Options" in
     * GuzzleHttp docs: http://docs.guzzlephp.org/en/stable/request-options.html
     *
     * @param string $target Target language code
     * @param string|null $source Source language code (null for automatic language detection)
     * @param array $options HTTP client configuration options
     * @param TokenProviderInterface|null $tokenProvider
     * @param bool|string $preserveParameters Boolean or custom regex pattern to match parameters
     */
    public function __construct(string $target = 'en', string $source = null, array $options = [], $token = null, bool|string $preserveParameters = false,string $proxy = null)
    {
        $this->client = new Client();
        $this->setToken($token ?? $this->token)
            ->setOptions($options) // Options are already set in client constructor tho.
            ->setSource($source)
            ->setTarget($target)
            ->setProxy($proxy)
            ->preserveParameters($preserveParameters);
    }
    function updateTranslationTexts(array $dataToTranslate = []): self
    {
        $this->dataArray[0][0] = (array) $dataToTranslate;  // 更新要翻译的文本
        return $this;
    }
    /**
     * Set target language for translation.
     *
     * @param string $target Target language code
     * @return GoogleTranslate
     */
    public function setTarget(string $target): self
    {
        $this->target = $target;
        $this->dataArray[0][2] = $target ?? 'auto';
        return $this;
    }
    public function setProxy(string $proxy = null): self
    {
        $this->proxy = $proxy;
        return $this;
    }
    /**
     * Set source language for translation.
     *
     * @param string|null $source Source language code (null for automatic language detection)
     * @return GoogleTranslate
     */
    public function setSource(string $source = null): self
    {
        $this->source = $source ?? 'auto';
        $this->dataArray[0][1] = $source ?? 'auto';
        return $this;
    }

    /**
     * Set Google Translate URL base
     *
     * @param string $url Google Translate URL base
     * @return GoogleTranslate
     */
    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Set GuzzleHttp client options.
     *
     * @param array $options HTTP client options.
     * @return GoogleTranslate
     */
    public function setOptions(array $options = []): self
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Set token provider.
     *
     * @return GoogleTranslate
     */
    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    /**
     * Get last detected source language
     *
     * @return string|null Last detected source language
     */
    public function getLastDetectedSource(): ?string
    {
        return $this->lastDetectedSource;
    }

    /**
     * Override translate method for static call.
     *
     * @param string $string String to translate
     * @param string $target Target language code
     * @param string|null $source Source language code (null for automatic language detection)
     * @param array $options HTTP client configuration options
     * @param TokenProviderInterface|null $tokenProvider Custom token provider
     * @param bool|string $preserveParameters Boolean or custom regex pattern to match parameters
     * @return null|string
     * @throws LargeTextException If translation text is too large
     * @throws RateLimitException If Google has blocked you for excessive requests
     * @throws TranslationRequestException If any other HTTP related error occurs
     * @throws TranslationDecodingException If response JSON cannot be decoded
     */
    public static function trans(array $texts, string $target = 'en', string $source = null, array $options = [], string $token = null, bool|string $preserveParameters = false,string $proxy = null): ?string
    {
        return (new self)
            ->setToken($token)
            ->setOptions($options) // Options are already set in client constructor tho.
            ->setSource($source)
            ->setProxy($proxy)
            ->setTarget($target)
            ->preserveParameters($preserveParameters)
            ->translate($texts);
    }

    /**
     * Translate text.
     *
     * This can be called from instance method translate() using __call() magic method.
     * Use $instance->translate($string) instead.
     *
     * @param string $string String to translate
     * @return string|null
     * @throws LargeTextException If translation text is too large
     * @throws RateLimitException If Google has blocked you for excessive requests
     * @throws TranslationRequestException If any other HTTP related error occurs
     * @throws TranslationDecodingException If response JSON cannot be decoded
     */
    public function translate(array $texts): ?array
    {
        // If the source and target languages are the same, just return the string without any request to Google.
        if ($this->source === $this->target) {
            return $texts;
        }
        // Replace replaceable keywords with ${\d} for replacement later
        $responseArray = $this->getResponse($texts);

        // Check if translation exists
        if (empty($responseArray[0])) {
            return null;
        }
        $lang = $responseArray[1][0];
        if ($this->isValidLocale($lang)) {
            $this->lastDetectedSource = $lang;
        }
        return $responseArray[0];
    }

    /**
     * Set a custom pattern for extracting replaceable keywords from the string,
     * default to extracting words prefixed with a colon
     *
     * @example (e.g. "Hello :name" will extract "name")
     *
     * @param bool|string $pattern Boolean or custom regex pattern to match parameters
     * @return self
     */
    public function preserveParameters(bool|string $pattern = true): self
    {
        if ($pattern === true) {
            $this->pattern = '/:(\w+)/'; // Default regex
        } elseif ($pattern === false) {
            $this->pattern = null;
        } elseif (is_string($pattern)) {
            $this->pattern = $pattern;
        }

        return $this;
    }

    /**
     * Extract replaceable keywords from string using the supplied pattern
     *
     * @param string $string
     * @return string
     */
    protected function extractParameters(string $string): string
    {
        // If no pattern, return string as is
        if (!$this->pattern) {
            return $string;
        }

        // Replace all matches of our pattern with ${\d} for replacement later
        return preg_replace_callback(
            $this->pattern,
            function ($matches) {
                static $index = -1;

                $index++;

                return '${' . $index . '}';
            },
            $string
        );
    }

    /**
     * Inject the replacements back into the translated string
     *
     * @param string $string
     * @param array<string> $replacements
     * @return string
     */
    protected function injectParameters(string $string, array $replacements): string
    {
        return preg_replace_callback(
            '/\${(\d+)}/',
            fn($matches) => $replacements[$matches[1]],
            $string
        );
    }

    /**
     * Extract an array of replaceable parts to be injected into the translated string
     * at a later time
     *
     * @return array<string>
     */
    protected function getParameters(string $string): array
    {
        $matches = [];

        // If no pattern is set, return empty array
        if (!$this->pattern) {
            return $matches;
        }

        // Find all matches for the pattern in our string
        preg_match_all($this->pattern, $string, $matches);

        return $matches[0];
    }

    /**
     * Get response array.
     *
     * @param array $texts String to translate
     * @return array Response
     * @throws LargeTextException If translation text is too large
     * @throws RateLimitException If Google has blocked you for excessive requests
     * @throws TranslationRequestException If any other HTTP related error occurs
     * @throws TranslationDecodingException If response JSON cannot be decoded
     */
    public function getResponse(array $texts): array
    {
        $this->updateTranslationTexts($texts);
        $dataArray = json_encode($this->dataArray);
        $headers = [
            'Accept: */*',
            'Accept-Encoding: gzip, deflate, br, zstd',
            //'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8,en-GB;q=0.7,en-US;q=0.6',
            'Content-Length: ' . strlen($dataArray),
            'Content-Type: application/json+protobuf',
            //'Origin: https://github-com.translate.goog',
            //'Referer: https://github-com.translate.goog/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36 Edg/123.0.0.0',
            'X-Goog-Api-Key: '.$this->token
        ];
        try {
            $ch = curl_init($this->url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $dataArray);
            curl_setopt($ch, CURLOPT_ENCODING, "");
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if($this->proxy){
                curl_setopt($ch, CURLOPT_PROXY, $this->proxy); 
            }
            $body = curl_exec($ch);
        } catch (\Exception $e) {
            match ($e->getCode()) {
                429, 503 => throw new RateLimitException($e->getMessage(), $e->getCode()),
                413 => throw new LargeTextException($e->getMessage(), $e->getCode()),
                default => throw new TranslationRequestException($e->getMessage(), $e->getCode()),
            };
        } catch (Throwable $e) {
            throw new TranslationRequestException($e->getMessage(), $e->getCode());
        }

        //$body = $response->getBody(); // Get response body
        // Decode JSON data
        try {
            $bodyArray = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TranslationDecodingException('Data cannot be decoded or it is deeper than the recursion limit');
        }

        return $bodyArray;
    }

    /**
     * Check if given locale is valid.
     *
     * @param string $lang Language code to verify
     * @return bool
     */
    protected function isValidLocale(string $lang): bool
    {
        return (bool) preg_match('/^([a-z]{2,3})(-[A-Za-z]{2,4})?$/', $lang);
    }
}
