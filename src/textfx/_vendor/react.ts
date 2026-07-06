import React from 'react';
import ReactDOM from 'react-dom';
import * as ReactDOMClient from 'react-dom/client';
import * as jsxRuntime from 'react/jsx-runtime';

// Expose React globals for text effect chunks (which use React as external)
(window as any).React = React;
(window as any).ReactDOM = { ...ReactDOM, ...ReactDOMClient };
(window as any).ReactJSXRuntime = jsxRuntime;
