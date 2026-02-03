#!/bin/bash
export HOME=/root

# Compose Manager - Docker Compose wrapper script
# Provides retry logic, error handling, and result tracking

# Configuration - can be overridden via environment
MAX_RETRIES=${COMPOSE_MAX_RETRIES:-3}
RETRY_DELAY=${COMPOSE_RETRY_DELAY:-5}
PULL_TIMEOUT=${COMPOSE_PULL_TIMEOUT:-600}

SHORT=e:,c:,f:,p:,d:,o:,g:,s:
LONG=env,command:,file:,project_name:,project_dir:,override:,profile:,debug,recreate,stack-path:
OPTS=$(getopt -a -n compose --options $SHORT --longoptions $LONG -- "$@")

eval set -- "$OPTS"

envFile=""
files=""
project_dir=""
stack_path=""
options=""
command_options=""
debug=false

# Logging helper
log_msg() {
    local level="$1"
    local msg="$2"
    logger -t "compose.manager" "[$level] $msg"
    if [ "$debug" = true ]; then
        echo "[$level] $msg"
    fi
}

# Save operation result to stack directory
save_result() {
    local result="$1"
    local exit_code="$2"
    local operation="$3"
    
    if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        echo "{\"result\":\"$result\",\"exit_code\":$exit_code,\"operation\":\"$operation\",\"timestamp\":\"$(date -Iseconds)\"}" > "$stack_path/last_result.json"
    fi
}

# Run command with retry logic for transient failures
# Usage: run_with_retry "command" "description" [retry_on_pattern]
run_with_retry() {
    local cmd="$1"
    local desc="$2"
    local retry_pattern="${3:-error|timeout|connection refused|no such host|temporary failure}"
    local attempt=1
    local exit_code=0
    local output=""
    local temp_file=$(mktemp)
    
    while [ $attempt -le $MAX_RETRIES ]; do
        if [ "$debug" = true ]; then
            log_msg "DEBUG" "Attempt $attempt/$MAX_RETRIES: $desc"
        fi
        
        # Run command and capture output + exit code
        eval "$cmd" 2>&1 | tee "$temp_file"
        exit_code=${PIPESTATUS[0]}
        output=$(cat "$temp_file")
        
        if [ $exit_code -eq 0 ]; then
            rm -f "$temp_file"
            return 0
        fi
        
        # Check if error is retryable (network/transient issues)
        if echo "$output" | grep -qiE "$retry_pattern" && [ $attempt -lt $MAX_RETRIES ]; then
            log_msg "WARN" "Transient error detected, retrying in ${RETRY_DELAY}s... (attempt $attempt/$MAX_RETRIES)"
            echo ""
            echo "⚠ Transient error detected, retrying in ${RETRY_DELAY}s... (attempt $attempt/$MAX_RETRIES)"
            echo ""
            sleep $RETRY_DELAY
            attempt=$((attempt + 1))
        else
            # Non-retryable error or max retries reached
            rm -f "$temp_file"
            if [ $attempt -ge $MAX_RETRIES ]; then
                log_msg "ERROR" "Command failed after $MAX_RETRIES attempts: $desc"
                echo ""
                echo "✗ Command failed after $MAX_RETRIES attempts"
            fi
            return $exit_code
        fi
    done
    
    rm -f "$temp_file"
    return $exit_code
}

while :
do
  case "$1" in
    -e | --env )
      envFile="$2"
      shift 2
      
      if [ -f $envFile ]; then
        echo "using .env: $envFile"
      else
        echo ".env doesn't exist: $envFile"
        exit
      fi

      envFile="--env-file ${envFile@Q}"
      ;;
    -c | --command )
      command="$2"
      shift 2
      ;;
    -f | --file )
      files="${files} -f ${2@Q}"
      shift 2
      ;;
    -p | --project_name )
      name="$2"
      shift 2
      ;;
    -d | --project_dir )
      if [ -d "$2" ]; then
        for file in $( find $2 -maxdepth 1 -type f -name '*compose*.yml' ); do
          files="$files -f ${file@Q}"
        done
      fi
      shift 2
      ;;
    -g | --profile )
      options="${options} --profile $2"
      shift 2
      ;;
    --recreate )
      command_options="${command_options} --force-recreate"
      shift;
      ;;
    -s | --stack-path )
      stack_path="$2"
      shift 2
      ;;
    --debug )
      debug=true
      shift;
      ;;
    --)
      shift;
      break
      ;;
    *)
      echo "Unexpected option: $1"
      ;;
  esac
done

case $command in

  up)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose $envFile $files $options -p $name up $command_options -d"
    fi
    
    run_with_retry "docker compose $envFile $files $options -p \"$name\" up $command_options -d" "start stack $name"
    exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
      # Save stack started timestamp
      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        date -Iseconds > "$stack_path/started_at"
      fi
      save_result "success" $exit_code "up"
      echo ""
      echo "✓ Stack $name started successfully"
    else
      save_result "failed" $exit_code "up"
      log_msg "ERROR" "Failed to start stack $name (exit code: $exit_code)"
      echo ""
      echo "✗ Stack $name failed to start (exit code: $exit_code)"
    fi
    ;;

  down)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose $envFile $files $options -p $name down"
    fi
    
    eval docker compose $envFile $files $options -p "$name" down 2>&1
    exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
      save_result "success" $exit_code "down"
      echo ""
      echo "✓ Stack $name stopped successfully"
    else
      save_result "failed" $exit_code "down"
      log_msg "ERROR" "Failed to stop stack $name (exit code: $exit_code)"
      echo ""
      echo "✗ Stack $name failed to stop (exit code: $exit_code)"
    fi
    ;;

  pull)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose $envFile $files $options -p $name pull"
    fi
    
    run_with_retry "docker compose $envFile $files $options -p \"$name\" pull" "pull images for $name"
    exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
      save_result "success" $exit_code "pull"
      echo ""
      echo "✓ Images pulled successfully for $name"
    else
      save_result "failed" $exit_code "pull"
      log_msg "ERROR" "Failed to pull images for $name (exit code: $exit_code)"
      echo ""
      echo "✗ Failed to pull images for $name (exit code: $exit_code)"
    fi
    ;;
    
  update)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose $envFile $files $options -p $name images -q"
      log_msg "DEBUG" "docker compose $envFile $files $options -p $name pull"
      log_msg "DEBUG" "docker compose $envFile $files $options -p $name up -d --build"
    fi

    # Capture current images for cleanup later
    images=()
    images+=( $(docker compose $envFile $files $options -p "$name" images -q 2>/dev/null) )

    if [ ${#images[@]} -eq 0 ]; then   
      delete="-f"
      files_arr=( $files ) 
      files_arr=( ${files_arr[@]/$delete} )
      if (( ${#files_arr[@]} )); then
        services=( $(cat ${files_arr[*]//\'/} | sed -n 's/image:\(.*\)/\1/p') )

        for image in "${services[@]}"; do
          images+=( $(docker images -q --no-trunc ${image} 2>/dev/null) )
        done
      fi

      images=( ${images[*]##sha256:} )
    fi
    
    # Pull with retry logic (most likely to have transient network failures)
    echo "Pulling latest images..."
    run_with_retry "docker compose $envFile $files $options -p \"$name\" pull" "pull images for $name"
    pull_exit=$?
    
    if [ $pull_exit -ne 0 ]; then
      save_result "failed" $pull_exit "update"
      log_msg "ERROR" "Failed to pull images for $name, aborting update"
      echo ""
      echo "✗ Failed to pull images for $name, update aborted"
      exit $pull_exit
    fi
    
    # Recreate containers with new images
    echo ""
    echo "Recreating containers..."
    run_with_retry "docker compose $envFile $files $options -p \"$name\" up -d --build" "recreate containers for $name"
    up_exit=$?

    if [ $up_exit -eq 0 ]; then
      # Clean up old images
      new_images=( $(docker compose $envFile $files $options -p "$name" images -q 2>/dev/null) )
      for target in "${new_images[@]}"; do
        for i in "${!images[@]}"; do
          if [[ ${images[i]} = $target ]]; then
            unset 'images[i]'
          fi
        done
      done

      if (( ${#images[@]} )); then
        if [ "$debug" = true ]; then
          log_msg "DEBUG" "docker rmi ${images[*]}"
        fi
        echo ""
        echo "Cleaning up old images..."
        eval docker rmi ${images[*]} 2>/dev/null || true
      fi
      
      # Save stack started timestamp after update
      if [ -n "$stack_path" ] && [ -d "$stack_path" ]; then
        date -Iseconds > "$stack_path/started_at"
      fi
      save_result "success" 0 "update"
      echo ""
      echo "✓ Stack $name updated successfully"
    else
      save_result "failed" $up_exit "update"
      log_msg "ERROR" "Failed to update stack $name (exit code: $up_exit)"
      echo ""
      echo "✗ Stack $name failed to update (exit code: $up_exit)"
    fi
    ;;

  stop)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose $envFile $files $options -p $name stop"
    fi
    
    eval docker compose $envFile $files $options -p "$name" stop 2>&1
    exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
      save_result "success" $exit_code "stop"
      echo ""
      echo "✓ Stack $name stopped successfully"
    else
      save_result "failed" $exit_code "stop"
      log_msg "ERROR" "Failed to stop stack $name (exit code: $exit_code)"
      echo ""
      echo "✗ Stack $name failed to stop (exit code: $exit_code)"
    fi
    ;;

  list) 
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose ls -a --format json"
    fi
    eval docker compose ls -a --format json 2>&1
    ;;

  ps)
    # Get all compose containers with their status/uptime
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker ps -a --filter label=com.docker.compose.project --format json"
    fi
    eval docker ps -a --filter 'label=com.docker.compose.project' --format json 2>&1
    ;;

  logs)
    if [ "$debug" = true ]; then
      log_msg "DEBUG" "docker compose $envFile $files $options logs -f"
    fi
    eval docker compose $envFile $files $options logs -f 2>&1
    ;;

  *)
    echo "Unknown command: $command"
    log_msg "ERROR" "Unknown command: $command (name: $name, files: $files)"
    exit 1
    ;;
esac