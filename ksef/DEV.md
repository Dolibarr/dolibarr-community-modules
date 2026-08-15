## Building from Source

Currently only tested on Ubuntu Linux

1. Clone the repository

```
git clone https://github.com/InPoint-Automation/Dolibarr-KSeF-Module.git
cd Dolibarr-KSeF-Module
```

2. Build distribution ZIP

```
./build/build.sh
```

The script will download necessary build tools (composer, php-scoper), install dependencies, and create a distribution
ZIP file.

Note: Dependency scoping is currently disabled in scope-dependencies.php