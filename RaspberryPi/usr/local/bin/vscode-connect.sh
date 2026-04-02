#!/bin/bash
code --remote ssh-remote+$(hostname -I | awk '{print $1}')
