FROM nginx:1-alpine

WORKDIR /var/www

COPY . /var/www
COPY docker/nginx/conf.d /etc/nginx/conf.d

RUN apk add --no-cache bash nano && \
    echo 'alias ll="ls -larth"' >> ~/.bashrc
