<?php

namespace Core2;

require_once("HttpException.php");

use BadMethodCallException;
use Core2\Mod\Webservice\Webtokens\Webtoken;
use Exception;
use ModAdminApi;

class Api extends Acl
{

    /**
     * свойства текущего запроса
     * @var array
     */
    private static array $route = [];

    /**
     * параметры запроса
     * @param array $route
     */
    public function __construct(array $route) {
        parent::__construct();
        self::$route = $route;
    }


    /**
     * @return mixed
     * @throws Exception
     */
    public function dispatchApi(): mixed {

        $module = self::$route['api'];
        $action = self::$route['action'];

        ob_start();
        $spent_time = microtime(true);

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'DELETE' &&
                ! empty(self::$route['query']) &&
                str_starts_with(self::$route['query'], 'mod_') &&
                str_contains(self::$route['query'], '.')
            ) {
                //возможно это удаление из браузера
                //удаляют запись из таблицы
                $route = self::$route;
                $query = explode('=', $route['query']);
                $route['params'] = [
                    '_resource' => key($route['params']),
                    '_field' => $query[0],
                    '_value' => $query[1]
                ];
                $route['query'] = '';
                Registry::set('route', $route);

                require_once 'core2/mod/admin/ModAdminApi.php';
                $coreController = new ModAdminApi();
                $result = $coreController->action_index();

                if (is_array($result)) {
                    $result = json_encode($result);
                }

            } else {
                Registry::set('context', array($module, $action));

                if ($module == 'admin') {
                    require_once 'core2/mod/admin/ModAdminApi.php';
                    $coreController = new ModAdminApi();
                    $action         = "action_" . $action;

                    if ( ! method_exists($coreController, $action)) {
                        $msg = sprintf($this->translate->tr("Метод %s не существует"), $action);
                        throw new BadMethodCallException($msg, 404);
                    }

                    if (str_starts_with(self::$route['query'], 'core_') && str_contains(self::$route['query'], '.')) {
                        //удаляют запись из интерфейса модуля Админ
                        $route = self::$route;
                        $query = explode('=', $route['query']);
                        $route['params'] = [
                            '_resource' => key($route['params']),
                            '_field' => $query[0],
                            '_value' => $query[1]
                        ];
                        $route['query'] = '';
                        Registry::set('route', $route);
                        $coreController = new ModAdminApi(); //в контролер будет передан новый роутинг
                    }

                    $result = $coreController->$action();

                    if (is_array($result)) {
                        $result = json_encode($result);
                    }

                } else {
                    $this->checkModule($module, $action);

                    $location            = $this->getModuleLocation($module);
                    $mod_controller_name = "Mod" . ucfirst(strtolower($module)) . "Api";
                    $this->requireController($location, $mod_controller_name);

                    $modController = new $mod_controller_name();
                    $action        = "action_" . $action;

                    if ( ! method_exists($modController, $action)) {
                        $msg = sprintf($this->translate->tr("Метод %s не существует"), $action);
                        throw new BadMethodCallException($msg, 404);
                    }

                    $result = $modController->$action();

                    if (is_array($result)) {
                        $result = json_encode($result);
                    }
                }
            }

        } catch (HttpException $e) {
            $result = Error::catchJsonException([
                'msg'  => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ], $e->getCode() ?: 500);
        }

        $result = ob_get_clean() . $result;


        if ($this->isModuleActive('webservice')) {
            $auth = Registry::get('auth');

            if ($auth->log_request) {
                $spent_time = microtime(true) - $spent_time;

                if ($auth->log_request->apikey) {
                    $reg_apikey = $this->db->fetchRow("
                        SELECT wr.is_log_output_sw,
                               wr.is_log_output_header_sw
                        FROM mod_webservice_apikeys AS wa
                            JOIN mod_webservice_regapikeys AS wr ON wa.parent_id = wr.id
                        WHERE wa.apikey = ? 
                    ", $auth->log_request->apikey);

                } elseif ($auth->log_request->webtoken) {
                    $reg_apikey = $this->db->fetchRow("
                        SELECT wr.is_log_output_sw,
                               wr.is_log_output_header_sw
                        FROM mod_webservice_webtokens AS ww
                            JOIN mod_webservice_regapikeys AS wr ON ww.parent_id = wr.id
                        WHERE ww.token = ? 
                    ", $auth->log_request->webtoken);

                } else {
                    $reg_apikey = null;
                }

                $log_data = [];

                if ($reg_apikey && $reg_apikey['is_log_output_sw'] == 'Y') {
                    $log_data['output_data'] = $result;
                }
                if ($reg_apikey && $reg_apikey['is_log_output_header_sw'] == 'Y') {
                    $log_data['output_headers'] = json_encode(headers_list());
                }

                $log_data['mem_peak']         = memory_get_peak_usage(true);
                $log_data['output_http_code'] = http_response_code();
                $log_data['output_size']      = isset($result) ? strlen($result) : 0;
                $log_data['spent_time']       = $spent_time;
                $auth->log_request->update($log_data);
            }
        }

        return $result;
    }


    /**
     * Проверка наличия и целостности файла контроллера
     *
     * @param $location - путь до файла
     * @param $apiController - название файла контроллера
     *
     * @throws Exception
     */
    private function requireController(string $location, string $apiController): void {
        $controller_path = $location . "/" . $apiController . ".php";
        if (!file_exists($controller_path)) {
            $msg = sprintf($this->translate->tr("Модуль %s не найден"), $apiController);
            throw new Exception($msg, 404);
        }
        $autoload = $location . "/vendor/autoload.php";
        if (file_exists($autoload)) { //подключаем автозагрузку если есть
            require_once $autoload;
        }
        require_once $controller_path; // подлючаем контроллер
        if (!class_exists($apiController)) {
            $msg = sprintf($this->translate->tr("Модуль %s сломан"), $location);
            throw new Exception($msg, 500);
        }
    }


    /**
     * проверка модуля на доступность
     * @param $module
     * @param $action
     * @return void
     * @throws Exception
     */
    public function checkModule($module, $action): void {
        if ($action == 'index') {
            $_GET['action'] = "index";

            if ( ! $this->isModuleActive($module)) {
                $msg = sprintf($this->translate->tr("Модуль %s не существует"), $module);
                throw new Exception($msg, 404);
            }

            if (! $this->checkAcl($module)) {
                $msg = $this->translate->tr("Доступ закрыт!");
                throw new Exception($msg, 403);
            }
        }
        else {
            $submodule_id = $module . '_' . $action;
            if ( ! $this->isModuleActive($submodule_id)) {
                $msg = sprintf($this->translate->tr("Субмодуль %s не существует"), $action);
                throw new Exception($msg, 404);
            }
            $mods = $this->getSubModule($submodule_id);

            if ($module == 'auth') {
                //API вызовы в модуль auth нельзя проверить на доступ
                return;
            }

            //TODO перенести проверку субмодуля в контроллер модуля
            if ($mods['sm_id'] && !$this->checkAcl($submodule_id)) {
                $msg = $this->translate->tr("Доступ закрыт!");
                throw new Exception($msg, 403);
            }
        }
    }

}