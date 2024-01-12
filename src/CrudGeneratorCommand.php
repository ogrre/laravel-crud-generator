<?php

namespace Ogrre\CrudGenerator;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
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
        $modelName = $this->argument('name');
        $attributes = $this->askForAttributes();
        $relations = $this->askForRelations();

        $this->generateModel($modelName, $attributes, $relations);
        $this->generateMigration($modelName, $attributes, $relations);
        $this->generateController($modelName);
        $this->generateRequests($modelName, $attributes);

        $this->info('CRUD for ' . $modelName . ' generated successfully along with migration.');
    }

    /**
     * @return array
     */
    protected function askForAttributes(): array
    {
        $attributes = [];
        while (true) {
            $name = $this->ask('Nom de l\'attribut (laisser vide pour terminer)');

            if (empty($name)) {
                break;
            }

            $type = $this->choice(
                'Type de l\'attribut',
                ['string', 'integer', 'float', 'boolean', 'date', 'datetime', 'text'],
                'string'
            );

            $isNullable = $this->confirm('L\'attribut peut-il être nul ?', false);

            $attributes[$name] = ['type' => $type, 'nullable' => $isNullable];
        }

        return $attributes;
    }

    /**
     * @return array
     */
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

            $relations[] = ['type' => $relationType, 'model' => $relatedModel];
        }

        return $relations;
    }

    /**
     * @param $modelName
     * @param $attributes
     * @param $relations
     * @return void
     * @throws FileNotFoundException
     */
    protected function generateModel($modelName, $attributes, $relations): void
    {
        $modelTemplate = $this->files->get(__DIR__ . '/stubs/model.stub');

        $attributesArray = $this->generateAttributes($attributes);
        $fillableAttributes = implode(', ', array_map(function ($attr) {
            return "'$attr'";
        }, array_keys($attributesArray)));

        $modelTemplate = str_replace('{{modelName}}', $modelName, $modelTemplate);
        $modelTemplate = str_replace('{{fillableAttributes}}', $fillableAttributes, $modelTemplate);
        $modelTemplate = str_replace('{{relations}}', $this->generateRelations($relations), $modelTemplate);

        $this->files->put(app_path("/Models/{$modelName}.php"), $modelTemplate);
    }

    /**
     * @param array $attributes
     * @return array
     */
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

    /**
     * @param array $relations
     * @return string
     */
    protected function generateRelations(array $relations): string
    {
        $relationMethods = '';

        foreach ($relations as $relation) {
            $methodName = Str::camel(Str::plural($relation['model']));
            $relationType = $relation['type'];
            $relatedModelClass = $relation['model'];

            $relationMethods .= "\n\tpublic function $methodName() {\n";
            $relationMethods .= "\t\treturn \$this->$relationType($relatedModelClass::class);\n";
            $relationMethods .= "\t}\n";
        }

        return $relationMethods;
    }

    /**
     * @param $modelName
     * @param $attributes
     * @param $relations
     * @return void
     * @throws FileNotFoundException
     */
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

    /**
     * @param $attributes
     * @param $relations
     * @return string
     */
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

    /**
     * @param $modelName
     * @return void
     * @throws FileNotFoundException
     */
    protected function generateController($modelName): void
    {
        $controllerTemplate = Str::replaceArray(
            '{{}}',
            [$modelName, Str::plural($modelName), Str::singular($modelName)],
            $this->files->get(__DIR__ . '/stubs/controller.stub')
        );

        $this->files->put(app_path("/Http/Controllers/{$modelName}Controller.php"), $controllerTemplate);
    }

    /**
     * @param $modelName
     * @param $attributes
     * @return void
     * @throws FileNotFoundException
     */
    protected function generateRequests($modelName, $attributes)
    {
        foreach (['Store', 'Update'] as $type) {
            $className = "{$type}{$modelName}Request";
            $rules = $this->generateRules($attributes);

            $formattedRules = $this->formatRulesForStub($rules);

            $requestTemplate = $this->files->get(__DIR__ . '/stubs/request.stub');
            $requestTemplate = str_replace('{{className}}', $className, $requestTemplate);
            $requestTemplate = str_replace('{{rules}}', $formattedRules, $requestTemplate);

            $this->files->put(app_path("/Http/Requests/{$className}.php"), $requestTemplate);
        }
    }

    /**
     * @param $rules
     * @return string
     */
    protected function formatRulesForStub($rules): string
    {
        return implode(",\n\t\t\t", array_map(function ($key, $rule) {
            return "'$key' => '$rule'";
        }, array_keys($rules), $rules));
    }

    /**
     * @param $attributes
     * @return array
     */
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

    /**
     * @param string $modelName
     * @return void
     */
    protected function generateRoutes(string $modelName): void
    {
        $modelNamePlural = Str::plural(Str::kebab($modelName));

        $routeTemplate = "\n// Routes for $modelName\n";
        $routeTemplate .= "Route::resource('$modelNamePlural', '{$modelName}Controller');\n";

        File::append(base_path('routes/web.php'), $routeTemplate);
    }
}
