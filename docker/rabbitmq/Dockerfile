FROM rabbitmq:4.1.7-management-alpine

# Download and install the delayed message exchange plugin for RabbitMQ 4.x
RUN apk add --no-cache wget && \
    cd /opt/rabbitmq/plugins && \
    wget https://github.com/rabbitmq/rabbitmq-delayed-message-exchange/releases/download/v4.1.0/rabbitmq_delayed_message_exchange-4.1.0.ez && \
    rabbitmq-plugins enable --offline rabbitmq_delayed_message_exchange && \
    apk del wget
