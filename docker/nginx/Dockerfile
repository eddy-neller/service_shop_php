FROM nginx:1-alpine

WORKDIR /var/www

COPY . /var/www

# Copy global nginx configuration and virtual hosts
ADD docker/nginx/nginx.conf /etc/nginx/
COPY docker/nginx/conf.d /etc/nginx/conf.d

RUN apk add --no-cache bash nano && \
    echo 'alias ll="ls -larth"' >> ~/.bashrc
