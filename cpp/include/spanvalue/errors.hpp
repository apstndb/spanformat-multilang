#pragma once

#include <stdexcept>
#include <string>

namespace spanvalue {

class SpanValueError : public std::runtime_error {
 public:
  using std::runtime_error::runtime_error;
};

class MalformedWireError : public SpanValueError {
 public:
  using SpanValueError::SpanValueError;
};

class UnknownTypeError : public SpanValueError {
 public:
  using SpanValueError::SpanValueError;
};

class MismatchedFieldsError : public SpanValueError {
 public:
  using SpanValueError::SpanValueError;
};

class EmptyTypeFQNError : public SpanValueError {
 public:
  using SpanValueError::SpanValueError;
};

class UnexpectedComplexValueKindError : public SpanValueError {
 public:
  using SpanValueError::SpanValueError;
};

class EmptyNullStringError : public SpanValueError {
 public:
  using SpanValueError::SpanValueError;
};

}  // namespace spanvalue
