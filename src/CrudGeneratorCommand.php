<?php

namespace Ogrre\CrudGenerator;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrudGeneratorCommand extends Command
{
    protected $signature = 'make:crud {name}';
    protected $description = 'Generate CRUD operations for a given model';

    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle()
    {
        $modelName = Str::singular(Str::ucfirst($this->argument('name')));
        $attributes = $this->askForAttributes();
        $relations = $this->askForRelations();

        $this->generateModel($modelName, $attributes, $relations);
        $this->generateMigration($modelName, $attributes, $relations);
        $this->generateController($modelName);
        $this->generateRequests($modelName, $attributes);
        $this->generateRoutes($modelName);

        $this->info('CRUD for ' . $modelName . ' generated successfully along with migration, controller, requests and routes.');
    }

    protected function askForAttributes(): array
    {
        $attributes = [];
        while (true) {
            $name = $this->ask('Attribute name (leave blank to finish)');

            if (empty($name)) {
                break;
            }

            $type = $this->choice(
                'Attribute type',
                ['string', 'integer', 'float', 'boolean', 'date', 'datetime', 'text'],
                'string'
            );

            $isNullable = $this->confirm('Can the attribute be nullable?', false);

            $attributes[Str::camel($name)] = ['type' => $type, 'nullable' => $isNullable];
        }

        return $attributes;
    }

    protected function askForRelations(): array
    {
        $relations = [];
        while (true) {
            if (!$this->confirm('Do you want to add a relation?', true)) {
                break;
            }

            $relationType = $this->choice(
                'Type of relation',
                ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany'],
                'hasOne'
            );

            $relatedModel = $this->ask('Name of the related model');

            if (!class_exists("App\Models\\" . $relatedModel)) {
                if ($this->confirm("The related model '$relatedModel' does not exist. Do you want to create it?", true)) {
                    $this->call('make:crud', ['name' => $relatedModel]);
                }
            }

            $relations[] = ['type' => $relationType, 'model' => $relatedModel];
        }

        return $relations;
    }

    protected function generateModel($modelName, $attributes, $relations): void
    {
        $modelTemplate = $this->files->get(__DIR__ . '/stubs/model.stub');

        $fillableAttributes = "'" . implode("', '", array_keys($attributes)) . "'";

        $modelTemplate = str_replace('{{modelName}}', $modelName, $modelTemplate);
        $modelTemplate = str_replace('{{fillableAttributes}}', $fillableAttributes, $modelTemplate);
        $modelTemplate = str_replace('{{relations}}', $this->generateRelations($relations), $modelTemplate);

        $modelPath = app_path("Models/{$modelName}.php");

        if ($this->files->exists($modelPath)) {
            if (!$this->confirm("The file {$modelPath} already exists. Do you want to overwrite it?", false)) {
                return;
            }
        }

        $this->files->put($modelPath, $modelTemplate);
    }

    protected function generateAttributes(array $attributes): array
    {
        $properties = '';
        $rules = '';

        foreach ($attributes as $name => $type) {
            $properties .= "\n\tprotected \$$name;";

            $rule = $type === 'string' ? 'required|max:255' : 'required';
            $rules .= "\n\t\t\t'$name' => '$rule',";
        }

        return compact('properties', 'rules');
    }

    protected function generateRelations(array $relations): string
    {
        $relationMethods = '';

        foreach ($relations as $relation) {
            $relatedModelClass = $relation['model'];

            $methodName = in_array($relation['type'], ['hasMany', 'belongsTo']) ? Str::plural(Str::camel($relatedModelClass)) : Str::camel($relatedModelClass);
            $relationType = $relation['type'];

            $relationMethods .= "\n\tpublic function $methodName() {\n";
            $relationMethods .= "\t\treturn \$this->$relationType($relatedModelClass::class);\n";
            $relationMethods .= "\t}\n";
        }

        return $relationMethods;
    }

    protected function generateMigration($modelName, $attributes, $relations): void
    {
        $className = 'Create' . Str::plural(Str::studly($modelName)) . 'Table';
        $tableName = Str::plural(Str::snake($modelName));
        $migrationName = date('Y_m_d_His') . '_create_' . $tableName . '_table.php';
        $migrationPath = database_path('migrations/' . $migrationName);

        $columns = $this->generateMigrationColumns($attributes, $relations);

        $migrationTemplate = $this->files->get(__DIR__ . '/stubs/migration.stub');
        $migrationTemplate = str_replace('{{className}}', $className, $migrationTemplate);
        $migrationTemplate = str_replace('{{tableName}}', $tableName, $migrationTemplate);
        $migrationTemplate = str_replace('{{columns}}', $columns, $migrationTemplate);

        $this->files->put($migrationPath, $migrationTemplate);
    }

    protected function generateMigrationColumns($attributes, $relations): string
    {
        $columns = '';
        foreach ($attributes as $name => $details) {
            $type = $details['type'];
            $nullable = $details['nullable'] ? '->nullable()' : '';

            $columns .= "\$table->$type('$name')$nullable;\n\t\t\t";
        }

        foreach ($relations as $relation) {
            if ($relation['type'] === 'belongsTo') {
                $relatedModel = $relation['model'];
                $foreignKey = Str::snake($relatedModel) . '_id';

                $columns .= "\$table->unsignedBigInteger('$foreignKey')->nullable();\n\t\t\t";
                $columns .= "\$table->foreign('$foreignKey')->references('id')->on('" . Str::plural(Str::snake($relatedModel)) . "')->onDelete('cascade');\n\t\t\t";
            }
        }

        return $columns;
    }

    protected function generateController($modelName): void
    {
        $modelNameSingular = Str::singular(Str::snake($modelName));
        $modelNamePlural = Str::plural(Str::snake($modelName));

        $controllerTemplate = $this->files->get(__DIR__ . '/stubs/controller.stub');
        $controllerTemplate = str_replace(['{{modelName}}', '{{modelNameSingular}}', '{{modelNamePlural}}', '{{viewFolder}}', '{{routeName}}'],
            [$modelName, $modelNameSingular, $modelNamePlural, $modelNamePlural, $modelNamePlural],
            $controllerTemplate
        );

        $modelPath = app_path("/Http/Controllers/{$modelName}Controller.php");

        if ($this->files->exists($modelPath)) {
            if (!$this->confirm("The file {$modelPath} already exists. Do you want to overwrite it?", false)) {
                return;
            }
        }

        $this->files->put($modelPath, $controllerTemplate);
    }

    protected function generateRequests($modelName, $attributes)
    {
        $requestsDirectory = app_path("/Http/Requests/{$modelName}/");
        if (!file_exists($requestsDirectory)) {
            mkdir($requestsDirectory, 0755, true);
        }

        foreach (['Store', 'Update'] as $type) {
            $className = "{$type}{$modelName}Request";
            $rules = $this->generateRules($attributes);

            $formattedRules = $this->formatRulesForStub($rules);

            $requestTemplate = $this->files->get(__DIR__ . '/stubs/request.stub');
            $requestTemplate = str_replace('{{className}}', $className, $requestTemplate);
            $requestTemplate = str_replace('{{modelName}}', $modelName, $requestTemplate);
            $requestTemplate = str_replace('{{rules}}', $formattedRules, $requestTemplate);

            $modelPath = $requestsDirectory . "{$className}.php";

            if ($this->files->exists($modelPath)) {
                if (!$this->confirm("The file {$modelPath} already exists. Do you want to overwrite it?", false)) {
                    return;
                }
            }

            $this->files->put($modelPath, $requestTemplate);
        }
    }


    protected function formatRulesForStub($rules): string
    {
        return implode(",\n\t\t\t", array_map(function ($key, $rule) {
            return "'$key' => '$rule'";
        }, array_keys($rules), $rules));
    }

    protected function generateRules($attributes): array
    {
        $rules = [];
        foreach ($attributes as $name => $details) {
            $type = $details['type'];
            $nullable = $details['nullable'] ? 'nullable' : 'required';
            $rule = $nullable;

            switch ($type) {
                case 'string':
                    $rule .= '|string|max:255';
                    break;
                case 'integer':
                    $rule .= '|integer';
                    break;
                case 'float':
                    $rule .= '|numeric';
                    break;
                case 'boolean':
                    $rule .= '|boolean';
                    break;
                case 'date':
                    $rule .= '|date';
                    break;
                case 'datetime':
                    $rule .= '|date_format:Y-m-d H:i:s';
                    break;
                case 'text':
                    $rule .= '|string';
                    break;
            }

            $rules[$name] = $rule;
        }

        return $rules;
    }

    protected function generateRoutes(string $modelName): void
    {
        $modelNamePlural = Str::plural(Str::kebab($modelName));

        $routeTemplate = "\n// Routes for {$modelName}\n";
        $routeTemplate .= "Route::resource('{$modelNamePlural}', App\Http\Controllers\'{$modelName}'Controller::class);\n";

        File::append(base_path('routes/web.php'), $routeTemplate);
    }
}
