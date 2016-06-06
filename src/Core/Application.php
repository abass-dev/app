<?php
namespace Bow\Core;

use Bow\Support\Str;
use Bow\Support\Util;
use Bow\Http\Request;
use Bow\Http\Response;
use Bow\Support\Logger;
use InvalidArgumentException;
use Bow\Exception\ApplicationException;

/**
 * Create and maintener by diagnostic developpers teams:
 *
 * @author Etchien Boa <geekroot9@gmail.com>
 * @author Franck Dakia <dakiafranck@gmail.com>
 * @package Bow\Core
 */
class Application
{
	/**
	 * Définition de contrainte sur un route.
	 *
	 * @var array
	 */ 
	private $with = [];

	/**
	 * Branchement global sur un liste de route
	 * 
	 * @var string
	 */
	private $branch = "";

	/**
	 * @var string
	 */
	private $specialMethod = null;
	
	/**
	 * Fonction lancer en cas d'erreur.
	 * 
	 * @var null|callable
	 */
	private $error404 = null;

	/**
	 * Method Http courrante.
	 * 
	 * @var string
	 */
	private $currentMethod = "";
	/**
	 * Enrégistre l'information la route courrante
	 * 
	 * @var string
	 */
	private $currentPath = "";

	/**
	 * Patter Singleton
	 * 
	 * @var Application
	 */
	private static $inst = null;

	/**
	 * Collecteur de route.
	 *
	 * @var array
	 */
	private static $routes = [];

	/**
	 * @var Request
	 */
	private $request;

	/**
	 * @var AppConfiguration|null
	 */
	private $config = null;

    /**
     * @var array
     */
	private $local = [];

    /**
     * @var bool
     */
    private $disableXpoweredBy = false;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * Private construction
	 *
	 * @param AppConfiguration $config
	 */
	private function __construct(AppConfiguration $config)
	{
		$this->config = $config;
        $this->request = $this->request();

		$logger = new Logger($config->getLogLevel(), $config->getLogpath() . "/error.log");
		$logger->register();
		$this->logger = $logger;
	}

	/**
	 * Private __clone
	 */
	private function __clone(){}

	/**
	 * Pattern Singleton.
	 * 
	 * @param AppConfiguration $config
	 * @return Application
	 */
	public static function configure(AppConfiguration $config)
	{
		if (static::$inst === null) {
			static::$inst = new static($config);
		}

		return static::$inst;
	}

	/**
	 * mount, ajoute un branchement.
	 *
	 * @param string $branch
	 * @param callable $cb
	 * @throws ApplicationException
	 * @return Application
	 */
	public function group($branch, Callable $cb)
	{
		$this->branch = $branch;

		if (is_array($cb)) {
			Util::launchCallback($cb, $this->request, $this->config->getNamespace());
		} else {
			if (!is_callable($cb)) {
				throw new ApplicationException("Callback are not define", E_ERROR);
			}
			call_user_func_array($cb, [$this->request]);
		}

        $this->branch = "";

		return $this;
	}

	/**
	 * get, route de type GET ou bien retourne les variable ajoutés dans Bow
	 *
	 * @param string $path
	 * @param Callable $cb [optional]
	 * @return Application|string
	 */
	public function get($path, $cb = null)
	{
		if ($cb === null) {
			$key = $path;
            if (in_array($key, $this->local)) {
               return $this->local[$key];
            } else {
                if (($method = $this->getConfigMethod($key, "get")) !== false) {
                    return $this->config->$method();
                } else {
                    return null;
                }
            }
		}

        return $this->routeLoader("GET", $path, $cb);
    }

	/**
	 * post, route de type POST
	 *
	 * @param string $path
	 * @param Callable $cb
	 * @return Application
	 */
	public function post($path, Callable $cb)
	{
		$body = $this->request->body();

		if ($body->has("method")) {
			$this->specialMethod = $method = strtoupper($body->get("method"));
			if (in_array($method, ["DELETE", "PUT"])) {
				$this->addHttpVerbe($method, $path, $cb);
			}
			return $this;
		}
		
		return $this->routeLoader("POST", $path, $cb);
	}

	/**
	 * any, route de tout type GET|POST|DELETE|PUT
	 *
	 * @param string $path
	 * @param Callable $cb
	 * @return Application
	 */
	public function any($path, Callable $cb)
	{
        foreach(["post", "delete", "put", "get"] as $function) {
            $this->$function($path, $cb);
        }

		return $this;
	}

	/**
	 * delete, route de tout type DELETE
	 *
	 * @param string $path
	 * @param callable $cb
	 * @return Application
	 */
	public function delete($path, Callable $cb)
	{
		return $this->addHttpVerbe("DELETE", $path, $cb);
	}

	/**
	 * put, route de tout type PUT
	 *
	 * @param string $path
	 * @param callable $cb
	 * @return Application
	 */
	public function put($path, Callable $cb)
	{
		return $this->addHttpVerbe("PUT", $path, $cb);
	}

	/**
	 * to404, Charge le fichier 404 en cas de non
	 * validite de la requete
	 *
	 * @param callable $cb
	 * @return Application
	 */
	public function to404(Callable $cb)
	{
		$this->error404 = $cb;
		return $this;
	}

	/**
	 * match, route de tout type de method
	 *
	 * @param array $methods
	 * @param string $path
	 * @param callable $cb
	 * @return Application
	 */
	public function match(array $methods, $path, Callable $cb)
	{
		foreach($methods as $method) {
			if ($this->request->method() === strtoupper($method)) {
				$this->routeLoader($this->request->method(), $path , $cb);
			}
		}

		return $this;
	}

	/**
	 * addHttpVerbe, permet d'ajouter les autres verbes http
	 * [PUT, DELETE, UPDATE, HEAD]
	 *
	 * @param string $method
	 * @param string $path
	 * @param callable $cb
	 *
	 * @return self
	 */
	private function addHttpVerbe($method, $path, Callable $cb)
	{
		$body = $this->request->body();
		$flag = true;

		if ($body !== null) {
			if ($body->has("method")) {
				if ($body->get("method") === $method) {
					$this->routeLoader($this->request->method(), $path, $cb);
				}
				$flag = false;
			}
		}

		if ($flag) {
			$this->routeLoader($method, $path, $cb);
		}

		return $this;
	}

	/**
	 * routeLoader, lance le chargement d'une route.
	 *
	 * @param string $method
	 * @param string $path
	 * @param callable|array $cb
	 *
	 * @return Application
	 */
	private function routeLoader($method, $path, Callable $cb)
	{
        // construction de la path original en fonction de la configuration de l'application
		$path = $this->config->getApproot() . $this->branch . $path;

        // Ajout d'un nouvelle route sur l'en definie.
		static::$routes[$method][] = new Route($path, $cb);

        // route courante
		$this->currentPath = $path;

        // methode courante
		$this->currentMethod = $method;

		return $this;
	}

	/**
	 * Lance une personnalisation de route.
	 * 
	 * @param array $otherRule
	 *
	 * @return Application
	 */
	public function where(array $otherRule)
	{
		// Quand le tableau de collection des contraintes sur les variables est vide
		if (empty($this->with)) {
			// Si on crée un nouvelle entre dans le tableau avec le nom de la methode HTTP
			// courante dont la valeur est un tableau, ensuite dans ce tableau on crée une
			// autre entré avec comme clé le path définie par le developpeur et pour valeur
			// les contraintes sur les variables.
			$this->with[$this->currentMethod] = [];
			$this->with[$this->currentMethod][$this->currentPath] = $otherRule;
		} else {
			// Quand le tableau de collection des contraintes sur les variables n'est pas vide
			// On vérifie l'existance de clé portant le nom de la methode HTTP courant
			// si la elle existe alors on fusionne l'ancien contenu avec la nouvelle.
			if (array_key_exists($this->currentMethod, $this->with)) {
				$this->with[$this->currentMethod] = array_merge(
					$this->with[$this->currentMethod], 
					[$this->currentPath => $otherRule]
				);
			}
		}

		return $this;
	}

	/**
	 * Lanceur de l'application
	 * 
	 * @param callable|null $cb
	 *
	 * @return mixed
	 */
	public function run($cb = null)
	{
        // Ajout de l'entête X-Powered-By
        if (!$this->disableXpoweredBy) {
            $this->response()->set("X-Powered-By", "Bow Framework");
        }

        // drapeaux d'erreur.
		$error = true;

		if (is_callable($cb)) {
			if (call_user_func_array($cb, [$this->request])) {
				die();
			}
		}

		$this->branch = "";
		$method = $this->request->method();

        // vérification de l'existance d'une methode spécial
        // de type DELETE, PUT
		if ($method == "POST") {
			if ($this->specialMethod !== null) {
				$method = $this->specialMethod;
			}
		}

        // vérification de l'existance de methode de la requete dans
        // la collection de route
		if (isset(static::$routes[$method])) {
			foreach (static::$routes[$method] as $key => $route) {

                // route doit être une instance de Route
				if (! ($route instanceof Route)) {
					continue;
				}

                // récupération du contenu de la where
				if (isset($this->with[$method][$route->getPath()])) {
					$with = $this->with[$method][$route->getPath()];
				} else {
					$with = [];
				}

                // Lancement de la recherche de la method qui arrivée dans la requete
                // ensuite lancement de la verification de l'url de la requete
                // execution de la fonction associé à la route.
				if ($route->match($this->request->uri(), $with)) {
					$this->currentPath = $route->getPath();

                    // appel requête fonction
					if ($this->config->getTakeInstanceOfApplicationInFunction()) {
						$response = $route->call($this->request, $this->config->getNamespace(), $this);
					} else {
						$response = $route->call($this->request, $this->config->getNamespace());
					}

                    if (is_string($response)) {
						$this->response()->send($response);
					} else if (is_array($response) || is_object($response)) {
						$this->response()->json($response);
					}

					$error = false;
				}
			}
		}

        // Si la route n'est pas enrégistre alors on lance une erreur 404
		if ($error === true) {
            // vérification et appel de la fonction du branchement 404
			if (is_callable($this->error404)) {
				call_user_func($this->error404);
			} else {
				$this->response()->send("Cannot " . $method . " " . $this->request->uri() . " 404");
			}
			$this->response()->code(404);
		}

		return $error;
	}

	/**
	 * Set, permet de rédéfinir quelque élément de la configuartion de
	 * façon élégante.
     *
	 * @param string $key
	 * @param string $value
	 *
	 * @throws InvalidArgumentException
	 *
     * @return Application|string
	 */
	public function set($key, $value)
	{
        $method = $this->getConfigMethod($key, "set");

        // Vérification de l
		if ($method) {
			if (method_exists($this->config, $method)) {
				return $this->config->$method($value);
			}
		} else {
            $this->local[$key] = $value;
		}

        return $this;
	}

	/**
	 * response, retourne une instance de la classe Response
	 * 
	 * @return Response
	 */
	private function response()
	{
		return Response::configure($this->config);
	}

	/**
	 * request, retourne une instance de la classe Request
	 * 
	 * @return Request
	 */
	private function request()
	{
		return Request::configure();
	}

    /**
     * @param string $key
     * @param string $prefix
	 *
     * @return string|bool
     */
    private function getConfigMethod($key, $prefix)
    {
        switch ($key) {
            case "view":
                $method = "Viewpath";
                break;
            case "engine":
                $method = "Engine";
                break;
            case "root":
                $method = "Approot";
                break;
            default:
                $method = false;
                break;
        }

        return is_string($method) ? $prefix . $method : $method;
    }

    /**
     * d'active l'ecriture le l'entête X-Powered-By
     */
    public function disableXPoweredBy()
    {
        $this->disableXpoweredBy = true;
    }

	/**
	 * REST API Maker.
	 *
     * @param string $url
     * @param string|array $controllerName
	 * @param array $where
	 * @return $this
	 * @throws ApplicationException
	 */
	public function resources($url, $controllerName, array $where = [])
	{
		if (!is_string($controllerName) && !is_array($controllerName)) {
			throw new ApplicationException("Le premier paramètre doit être un array ou une chaine de caractère", 1);
		}

		$controller = "";
		$internalMiddleware = null;
		$ignoreMethod = [];
		$valideMethod = [
			[
				"url"    => "/",
				"call"   => "index",
				"method" => "get"
			],
			[
				"url"    => "/",
				"call"   => "store",
				"method" => "post"
			],
			[
				"url"    => "/:id",
				"call"   => "show",
				"method" => "get"
			],
			[
				"url"    => "/:id",
				"call"   => "update",
				"method" => "put"
			],
			[
				"url"    => "/:id",
				"call"   => "destroy",
				"method" => "delete"
			],
			[
				"url"    => "/:id/edit",
				"call"   => "edit",
				"method" => "get"
			],
			[
				"url"    => "/create",
				"call"   => "create",
				"method" => "get"
			]
		];

		if (is_array($controllerName)) {
			if (isset($controllerName["middleware"])) {
				$internalMiddleware = $controllerName["middleware"];
				unset($controllerName["middleware"]);
				$next = Util::launchCallback(["middleware" => $internalMiddleware], $this->request);
				if ($next === false) {
					return $this;
				}
			}

			if (isset($controllerName["uses"])) {
				$controller = $controllerName["uses"];
				unset($controllerName["uses"]);
			}

			if (isset($controllerName["ignores"])) {
				$ignoreMethod = $controllerName["ignores"];
				unset($controllerName["ignores"]);
			}
		} else  {
			$controller = $controllerName;
		}

		// Url principal.
		$url = rtrim($url. "/");

        if (!empty($url)) {
            $url .= "/" . $url;
        }

		// Association de url prédéfinie
		foreach ($valideMethod as $key => $value) {
			if (!in_array($value["call"], $ignoreMethod)) {
				$controller = $controller . '@' . $value["call"];
				call_user_func_array([$this, $value["method"]], ["$url" . $value["url"], $controller]);
				if (!empty($where)) {
					$this->where($where);
				}
			}
		}

		return $this;
	}

	/**
	 * Fonction retournant une instance de logger.
	 *
	 * @return Logger
	 */
	public function log()
	{
		return $this->logger;
	}

	/**
	 * __call fonction magic php
	 *
	 * @param string $method
	 * @param array $param
	 *
	 * @throws ApplicationException
	 *
	 * @return mixed
	 */
	public function __call($method, array $param)
	{
		if (method_exists($this->config, $method)) {
			return call_user_func_array([$this->config, $method], $param);
		}

		if (in_array($this->local, $method)) {
			return call_user_func_array($this->local[$method], $param);
		}

		throw new ApplicationException("$method n'exist pas une methode.", E_ERROR);
	}
}