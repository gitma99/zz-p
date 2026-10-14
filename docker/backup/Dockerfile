FROM public.ecr.aws/ubuntu/ubuntu:24.04

# Prevent interactive prompts during installation
ENV DEBIAN_FRONTEND=noninteractive

# Install dependencies
RUN apt-get update && apt-get install -y \
	mysql-client \
	zip \
	curl \
	jq \
	tzdata \
	&& rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /backups

# Copy backup and restore scripts
COPY backup.sh /backup.sh
COPY restore.sh /restore.sh
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /backup.sh /restore.sh /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
