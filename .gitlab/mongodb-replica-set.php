<?php

declare(strict_types=1);

/*
 * Initialise le replica set du service `mongodb` de la CI, puis attend son election.
 *
 * En local, le healthcheck de docker-compose.yaml s'en charge. Un service GitLab n'a
 * pas de healthcheck : sans cette attente, les premiers tests partiraient contre un
 * serveur qui refuse encore les ecritures (`NotWritablePrimary`).
 *
 * Usage : php .gitlab/mongodb-replica-set.php <hote:port>
 */

$host = $argv[1] ?? 'mongodb:27017';
$manager = new MongoDB\Driver\Manager(sprintf('mongodb://%s/?directConnection=true&serverSelectionTimeoutMS=2000', $host));
$deadline = time() + 60;

while (true) {
    try {
        $hello = $manager->executeCommand('admin', new MongoDB\Driver\Command(['hello' => 1]))->toArray()[0];

        if (true === ($hello->isWritablePrimary ?? false)) {
            fwrite(STDOUT, sprintf("Replica set %s pret : %s est primaire.\n", $hello->setName, $host));
            exit(0);
        }

        // Sans `setName`, le serveur n'a encore aucune configuration de replica set.
        // Une configuration vide lui fait adopter son propre nom d'hote comme membre.
        if (!isset($hello->setName)) {
            $manager->executeCommand('admin', new MongoDB\Driver\Command(['replSetInitiate' => new stdClass()]));
        }
    } catch (MongoDB\Driver\Exception\Exception) {
        // Serveur pas encore en ecoute, ou initialisation deja lancee : on reessaie.
    }

    if (time() >= $deadline) {
        fwrite(STDERR, sprintf("Replica set non elu apres 60 s sur %s.\n", $host));
        exit(1);
    }

    sleep(1);
}
