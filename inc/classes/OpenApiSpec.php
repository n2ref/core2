<?php
namespace Core2;
require_once 'Db.php';


/**
 * @property \Core2\Model\Modules $dataModules
 */
class OpenApiSpec extends Db {

    /**
     * @return array
     * @throws \Exception
     */
    public function getSections(): array {

        $sections = [
            [ 'name' => 'core2', 'title' => 'Core2', ]
        ];

        $modules_list = $this->dataModules->getModuleList();
        $modules      = [];

        foreach ($modules_list as $module) {
            $modules[$module['module_id']] = $module;
        }

        foreach ($modules as $mod) {
            $location        = $this->getModuleLocation($mod['module_id']);
            $controller_path = "{$location}/Mod" . ucfirst(strtolower($mod['module_id'])) . "Api.php";

            if (file_exists($controller_path)) {
                if (file_exists("{$location}/Api/schema.json")) {
                    $sections[$mod['module_id']] = [
                        'name'  => $mod['module_id'],
                        'title' => trim(strip_tags($mod['m_name']))
                    ];

                } else {
                    require_once $controller_path;
                    $openapi        = \OpenApi\Generator::scan([$controller_path], ['exclude' => ['vendor'], 'pattern' => '*.php']);
                    $section_scheme = ($openapi)->toJson();

                    if ( ! empty($section_scheme)) {
                        $section_scheme = json_decode($section_scheme, true);

                        if (count($section_scheme) > 1) {
                            $sections[$mod['module_id']] = [
                                'name'  => $mod['module_id'],
                                'title' => trim(strip_tags($mod['m_name']))
                            ];
                        }
                    }
                }
            }
        }


        return array_values($sections);
    }


    /**
     * @param string $section
     * @return array
     * @throws \Exception
     */
    public function getSectionSchema(string $section): array {

        if ($section == 'core2') {
            $schema_content = file_get_contents(__DIR__ . '/../../schema.json');
            $section_schema = json_decode($schema_content, true);

        } else {
            $mods = $this->dataModules->getModuleList();

            foreach ($mods as $mod) {
                if ($mod['module_id'] == $section) {

                    $location    = $this->getModuleLocation($mod['module_id']);
                    $file_schema = "{$location}/Api/schema.json";

                    if (file_exists($file_schema)) {
                        $schema_content = file_get_contents($file_schema);
                        $section_schema = json_decode($schema_content, true);

                    } else {
                        $controller      = "Mod" . ucfirst(strtolower($mod['module_id'])) . "Api";
                        $controller_path = "{$location}/{$controller}.php";

                        if (file_exists($controller_path)) {
                            $section_schema = (\OpenApi\Generator::scan([$controller_path]))->toJson();
                        }
                    }
                    break;
                }
            }
        }


        if ( ! empty($section_schema) && is_array($section_schema)) {

            $current_server = "{$_SERVER['REQUEST_SCHEME']}://{$_SERVER['HTTP_HOST']}" . DOC_PATH;
            $current_server = rtrim($current_server, '/');

            $servers = [
                [ 'url' => $current_server ]
            ];

            if ( ! empty($section_schema['servers']) && is_array($section_schema['servers'])) {
                foreach ($section_schema['servers'] as $server) {
                    if ( ! empty($server['url']) && $current_server != rtrim($server['url'], '/')) {
                        $servers[] = $server;
                    }
                }
            }

            $section_schema['servers'] = $servers;

            return $section_schema;
        }

        return [];
    }
}
