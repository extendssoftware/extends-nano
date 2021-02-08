<?php
declare(strict_types=1);

namespace ExtendsSoftware\Atto;

use Closure;
use ReflectionFunction;
use RuntimeException;
use Throwable;
use function array_filter;
use function array_key_exists;
use function header;
use function is_file;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function strtok;

/**
 * Implementation of AttoInterface.
 *
 * @package ExtendsSoftware\Atto
 * @author  Vincent van Dijk <vincent@extends.nl>
 * @version 0.1.0
 * @see     https://github.com/extendssoftware/extends-atto
 */
class Atto implements AttoInterface
{
    /**
     * Filename for view file.
     *
     * @var string|null
     */
    protected ?string $view = null;

    /**
     * Filename for layout file.
     *
     * @var string|null
     */
    protected ?string $layout = null;

    /**
     * Routes in chronological order.
     *
     * @var array[]
     */
    protected array $routes = [];

    /**
     * Data container.
     *
     * @var array
     */
    protected array $data = [];

    /**
     * Callbacks for types of events.
     *
     * @var Closure[]
     */
    protected array $callbacks = [];

    /**
     * @inheritDoc
     */
    public function view(string $filename = null)
    {
        if ($filename === null) {
            return $this->view;
        }

        $this->view = $filename;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function layout(string $filename = null)
    {
        if ($filename === null) {
            return $this->layout;
        }

        $this->layout = $filename;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function data(string $name = null, $value = null)
    {
        if ($name === null) {
            return $this->data;
        }

        if ($value === null) {
            return $this->data[$name] ?? null;
        }

        $this->data[$name] = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function callback(string $event, Closure $callback = null)
    {
        if ($callback === null) {
            return $this->callbacks[$event] ?? null;
        }

        $this->callbacks[$event] = $callback;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function route(string $name, string $pattern = null, string $view = null, Closure $callback = null)
    {
        if ($pattern === null) {
            return $this->routes[$name] ?? null;
        }

        $this->routes[$name] = [
            'name' => $name,
            'pattern' => $pattern,
            'view' => $view,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function redirect(string $url, array $parameters = null, int $status = null): void
    {
        $route = $this->route($url);
        if ($route) {
            $url = $this->assemble($url, $parameters);
        }

        header('Location: ' . $url, true, $status ?: 301);
    }

    /**
     * @inheritDoc
     */
    public function assemble(string $name, array $parameters = null): string
    {
        $route = $this->route($name);
        if (!$route) {
            throw new RuntimeException(sprintf(
                'No route found with name "%s". Please check the name of the route or provide a new route with the ' .
                'same name.',
                $name
            ));
        }

        $parameters ??= [];
        $url = $route['pattern'];

        do {
            // Match optional parts inside out. Match everything inside brackets except a opening or closing bracket.
            $url = preg_replace_callback('~\[([^\[\]]+)]~', static function ($match) use ($parameters): string {
                try {
                    // Find parameters and check if parameter is provided.
                    return preg_replace_callback('~:([a-z][a-z0-9_]+)~i', static function ($match) use ($parameters): string {
                        $parameter = $match[1];
                        if (!isset($parameters[$parameter])) {
                            throw new RuntimeException('');
                        }

                        return (string)$parameters[$parameter];
                    }, $match[1]);
                } catch (Throwable $throwable) {
                    // Parameter for optional part not provided. Skip whole optional part and continue assembly.
                    return $throwable->getMessage();
                }
            }, $url, -1, $count);
        } while ($count > 0);

        // Find all required parameters.
        return preg_replace_callback('~:([a-z][a-z0-9_]+)~i', static function ($match) use ($route, $parameters): string {
            $parameter = $match[1];
            if (!isset($parameters[$parameter])) {
                throw new RuntimeException(sprintf(
                    'Required parameter "%s" for route name "%s" is missing. Please provide the required parameter ' .
                    'or change the route URL.',
                    $parameter,
                    $route['name']
                ));
            }

            return (string)$parameters[$parameter];
        }, $url);
    }

    /**
     * @inheritDoc
     */
    public function match(string $path): ?array
    {
        $path = strtok($path, '?');

        foreach ($this->routes as $route) {
            $pattern = $route['pattern'];
            if ($pattern === '*') {
                return $route;
            }

            do {
                // Replace everything inside brackets with an optional regular expression group inside out.
                $pattern = preg_replace('~\[([^\[\]]+)]~', '($1)?', $pattern, -1, $count);
            } while ($count > 0);

            // Replace all parameters with a named regular expression group which will not match a forward slash.
            $pattern = preg_replace('~:([a-z][a-z0-9_]*)~i', '(?<$1>[^/]+)', $pattern);
            $pattern = '~^' . $pattern . '$~';
            if (preg_match($pattern, $path, $matches)) {
                $route['matches'] = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return $route;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function render(string $filename, object $newThis = null): string
    {
        $closure = function () use ($filename) {
            ob_start();

            try {
                if (is_file($filename)) {
                    /** @noinspection PhpIncludeInspection */
                    include $filename;
                } else {
                    echo $filename;
                }

                return ob_get_clean();
            } catch (Throwable $throwable) {
                // Clean any output for only the error message to show.
                ob_end_clean();

                throw $throwable;
            }
        };

        return $closure->call($newThis ?: $this);
    }

    /**
     * @inheritDoc
     */
    public function call(Closure $callback, object $newThis, array $arguments = null)
    {
        $reflection = new ReflectionFunction($callback);
        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (!array_key_exists($name, $arguments ?? [])) {
                if ($parameter->isDefaultValueAvailable()) {
                    $args[] = $parameter->getDefaultValue();
                } elseif ($parameter->allowsNull()) {
                    $args[] = null;
                } else {
                    throw new RuntimeException(sprintf(
                        'Required argument "%s" for callback is not provided in the arguments array, does not has a ' .
                        'default value and is not nullable. Please provide the missing argument or give it a default ' .
                        'value.',
                        $name
                    ));
                }
            } else {
                $args[] = $arguments[$name];
            }
        }

        return $callback->call($newThis, ...$args ?? []);
    }

    /**
     * @inheritDoc
     */
    public function run(string $path = null): string
    {
        try {
            $callback = $this->callback(static::CALLBACK_ON_START);
            if ($callback) {
                $return = $this->call($callback, $this);
                if ($return) {
                    return (string)$return;
                }
            }

            $route = $this->match($path ?: $_SERVER['REQUEST_URI']);
            if ($route) {
                if ($route['view']) {
                    $this->view($route['view']);
                }

                if ($route['callback']) {
                    $return = $this->call($route['callback'], $this, $route['matches'] ?? []);
                    if ($return) {
                        return (string)$return;
                    }
                }
            }

            $render = '';
            $view = $this->view();
            if ($view) {
                $render = $this->render($view, $this);

                $this->data('view', $render);
            }

            $layout = $this->layout();
            if ($layout) {
                $render = $this->render($layout, $this);
            }

            $callback = $this->callback(static::CALLBACK_ON_FINISH);
            if ($callback) {
                $return = $this->call($callback, $this, [
                    'render' => $render,
                ]);
                if ($return) {
                    return (string)$return;
                }
            }

            return $render;
        } catch (Throwable $throwable) {
            try {
                $callback = $this->callback(static::CALLBACK_ON_ERROR);
                if ($callback) {
                    $return = $this->call($callback, $this, [
                        'throwable' => $throwable,
                    ]);
                    if ($return) {
                        return (string)$return;
                    }
                }
            } catch (Throwable $throwable) {
                return $throwable->getMessage();
            }

            return $throwable->getMessage();
        }
    }
}