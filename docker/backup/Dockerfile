FROM ubuntu:20.04

# Prevent interactive prompts during installation
ENV DEBIAN_FRONTEND=noninteractive

# Install dependencies
RUN apt-get update && apt-get install -y \
	mysql-client \
	zip \
	curl \
	jq \
	tzdata \
	python3 \
	python3-pip \
	&& rm -rf /var/lib/apt/lists/*

RUN pip3 install --no-cache-dir requests

# Set working directory
WORKDIR /backups

# Copy backup and restore scripts
COPY backup.sh /backup.sh
COPY restore.sh /restore.sh
COPY entrypoint.sh /entrypoint.sh
COPY send_telegram.py /send_telegram.py

RUN chmod +x /backup.sh /restore.sh /entrypoint.sh /send_telegram.py

ENTRYPOINT ["/entrypoint.sh"]
