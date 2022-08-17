# arche-schema-ingest

[![Latest Stable Version](https://poser.pugx.org/acdh-oeaw/arche-schema-ingest/v/stable)](https://packagist.org/packages/acdh-oeaw/arche-schema-ingest)
![Build status](https://github.com/acdh-oeaw/arche-schema-ingest/workflows/phpunit/badge.svg?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-schema-ingest/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-schema-ingest?branch=master)
[![License](https://poser.pugx.org/acdh-oeaw/arche-schema-ingest/license)](https://packagist.org/packages/acdh-oeaw/arche-schema-ingest)

Library for ingesting the [ACDH schema](https://github.com/acdh-oeaw/arche-schema) into the [ARCHE repository](https://github.com/acdh-oeaw/arche-core).

## Installation

* Obtain [composer](https://getcomposer.org/)
* Run `composer require arche-schema-ingest`

## Usage

Use one of provided scripts (paths relative to directory where you performed the installation):

* `vendor/bin/arche-import-ontology --user "{repoUser}" --pswd "{repoPswd}" {repositoryUrl}`
* `vendor/bin/arche-import-vocabularies --user "{repoUser}" --pswd "{repoPswd}" {repositoryUrl}`
* `vendor/bin/arche-check-ontology --user "{repoUser}" --pswd "{repoPswd}" {repositoryUrl}`

If you want to check all options available, run the script with the `--help` parameter.

