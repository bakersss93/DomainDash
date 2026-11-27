<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class ApiDocsController extends Controller
{
    private const YAML_PATH = 'openapi/domaindash-openapi.yaml';
    private const JSON_PATH = 'openapi/domaindash-openapi.json';

    public function yaml(): Response
    {
        $yaml = file_get_contents(resource_path(self::YAML_PATH));

        // Replace the templated server default ({baseUrl}) with this installation's URL.
        $url  = "'" . str_replace("'", "''", $this->baseUrl()) . "'";
        $yaml = preg_replace_callback(
            '/^(\s+baseUrl:\s*\n\s+default:\s*).*$/m',
            fn ($m) => $m[1] . $url,
            $yaml,
            1
        );

        return response($yaml, 200, [
            'Content-Type'        => 'application/yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="domaindash-openapi.yaml"',
        ]);
    }

    public function json(): Response
    {
        $spec = json_decode(file_get_contents(resource_path(self::JSON_PATH)), true, 512, JSON_THROW_ON_ERROR);
        $spec['servers'][0]['variables']['baseUrl']['default'] = $this->baseUrl();

        return response(
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            200,
            [
                'Content-Type'        => 'application/json; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="domaindash-openapi.json"',
            ]
        );
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
