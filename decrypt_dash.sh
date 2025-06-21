#!/bin/bash


# Config
INPUT_DIR="/c/xampp/htdocs/tataplay/segments/303"
OUTPUT_DIR="${INPUT_DIR}/decrypted"
KEY_HEX="46268cd5b1f350bda7127ae262081c05:e8ab6884bb0e318a2de6a40e6f803776"

# Create output directory if it doesn't exist
mkdir -p "$OUTPUT_DIR"

echo "🔓 Starting decryption of .dash and .m4s segments in: $INPUT_DIR"

# Loop over .m4s and .dash files
shopt -s nullglob
for file in "$INPUT_DIR"/*.m4s "$INPUT_DIR"/*.dash; do
    filename=$(basename "$file")
    output="$OUTPUT_DIR/$filename"
    
    echo "Decrypting: $filename"
    command="./mp4decrypt.exe --key \"$KEY_HEX\" \"$file\" \"$output\""
    echo "Command: $command"
    if [[ $? -eq 0 ]]; then
        echo "✅ Success: $filename"
    else
        echo "❌ Failed: $filename"
    fi
done

echo "🎉 Done. Decrypted files saved in: $OUTPUT_DIR"
