# arche-schema-ingest

Library for ingesting the [ACDH schema](https://github.com/acdh-oeaw/arche-schema) into the [ARCHE repository](https://github.com/acdh-oeaw/arche-core).

## Installation, configuration and usage

* Obtain [composer](https://getcomposer.org/)
* Run `composer require arche-schema-ingest`
* Obtain the ACDH schema with `composer require arche-schema`
* Fetch a `config.yaml` file from your repository instance's `/describe` API endpoint (e.g. http://arche.acdh.oeaw.ac.at/api/describe) and make sure it contains all `schema` properties mentioned in the `config-sample.yaml` file.
  Don't forget to provide credentials with repository write rights.
* Use one of provided scripts:
    * `./importOntology.php config.yaml vendor/acdh-oeaw/arche-schema/acdh-schema.owl`
    * `./importVocabularies.php config.yaml`
* Or use provided classes (see example usage in `importOntology.php` and `importVocabularies.php`

