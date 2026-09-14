FROM mongo:8

# L'authentification d'un replica set exige une cle interne, y compris avec un
# seul noeud. Elle est generee dans l'image afin de ne jamais etre versionnee.
RUN openssl rand -base64 756 > /etc/mongodb-keyfile \
    && chmod 400 /etc/mongodb-keyfile \
    && chown mongodb:mongodb /etc/mongodb-keyfile

# La configuration est copiee dans l'image plutot que montee en volume : elle fait
# partie de ce qui est construit et versionne, pas de l'environnement local.
COPY mongod.conf /etc/mongod.conf

# L'entrypoint officiel de l'image transmet cette commande a mongod. Sans `--config`,
# les reglages du fichier ci-dessus seraient ignores en silence.
CMD ["mongod", "--config", "/etc/mongod.conf"]
